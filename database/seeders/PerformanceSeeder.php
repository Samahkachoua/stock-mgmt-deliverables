<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A large dataset for performance checks (docs/performance.md): ITEMS items and
 * about MOVEMENTS ledger movements over the past year, on top of LocalSeeder.
 * Never in production; meant for a scratch database:
 *
 *   createdb stock_perf
 *   DB_DATABASE=stock_perf php artisan migrate --force
 *   DB_DATABASE=stock_perf php artisan db:seed --class=LocalSeeder
 *   DB_DATABASE=stock_perf php artisan db:seed --class=PerformanceSeeder
 *
 * Movements go through fn_post_movement (the database's own posting function),
 * so balances always equal the sum of the ledger. Documents are written in bulk
 * instead of through PostingService to keep seeding to a minute or two.
 */
class PerformanceSeeder extends Seeder
{
    public const ITEMS = 200;

    public const MOVEMENTS = 100_000;

    private const LINES_PER_DOCUMENT = 50;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('PerformanceSeeder must never run in production.');
        }

        mt_srand(2026);
        $warehouses = Warehouse::where('active', true)->orderBy('code')->get();
        $customers = Customer::where('active', true)->orderBy('code')->get();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $units = DB::table('unit')->pluck('id', 'code');

        [$products, $expiries] = $this->items($customers->pluck('id')->all(), $units['PC'], $units['BOX']);
        $start = now()->subYear()->startOfDay();
        $documents = 0;
        $lines = [];
        $number = 900000;

        // Stock first: one receipt per warehouse and customer with every item (and expiry date).
        foreach ($warehouses as $warehouse) {
            foreach ($customers as $customer) {
                $id = $this->document('IN', $number++, $warehouse->id, $customer->id, $user->id, $start);
                $documents++;
                $lineNo = 0;

                foreach ($products[$customer->id] as $product) {
                    foreach ($expiries[$product['id']] ?? [null] as $expiry) {
                        $lines[] = $this->line($id, ++$lineNo, $product['id'], $expiry, $units['PC'], 10000);
                    }
                }
            }
        }

        // Then receipts, issues and damage through the year, 50 lines each.
        $count = (int) ceil((self::MOVEMENTS - count($lines)) / self::LINES_PER_DOCUMENT);
        $types = ['IN', 'OUT', 'OUT', 'DAMAGE'];

        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i % count($types)];
            $customer = $customers[$i % $customers->count()];
            $at = $start->copy()->addMinutes((int) (($i + 1) / $count * 365 * 24 * 60));
            $id = $this->document($type, $number++, $warehouses[$i % $warehouses->count()]->id, $customer->id, $user->id, $at);
            $documents++;

            for ($n = 1; $n <= self::LINES_PER_DOCUMENT; $n++) {
                $product = $products[$customer->id][mt_rand(0, count($products[$customer->id]) - 1)];
                $choices = $expiries[$product['id']] ?? [null];
                $lines[] = $this->line($id, $n, $product['id'], $choices[mt_rand(0, count($choices) - 1)], $units['PC'], $type === 'IN' ? 5 : mt_rand(1, 3));
            }
        }

        DB::transaction(function () use ($lines) {
            foreach (array_chunk($lines, 2000) as $chunk) {
                DB::table('stock_transaction_line')->insert($chunk);
            }

            // Post in date order, through the database's posting function.
            DB::unprepared(<<<'SQL'
                DO $$
                DECLARE r record;
                BEGIN
                    FOR r IN
                        SELECT t.id AS transaction_id, l.id AS line_id, t.warehouse_id, t.customer_id, l.product_id, l.expiry_date, l.batch_no, l.qty_base,
                               CASE WHEN t.transaction_type = 'IN' THEN 'IN' ELSE 'OUT' END AS direction
                          FROM stock_transaction t
                          JOIN stock_transaction_line l ON l.transaction_id = t.id
                         WHERE t.doc_no LIKE '%-9%' AND NOT EXISTS (SELECT 1 FROM stock_movement m WHERE m.line_id = l.id)
                         ORDER BY t.created_at, t.doc_no, l.line_no
                    LOOP
                        PERFORM fn_post_movement(r.transaction_id, r.line_id, r.warehouse_id, r.customer_id, r.product_id, r.expiry_date, r.batch_no, r.direction::movement_direction, r.qty_base);
                    END LOOP;
                END $$;
            SQL);

            // Movements dated like their documents (posting time).
            DB::statement('UPDATE stock_movement m SET created_at = t.created_at FROM stock_transaction t WHERE m.transaction_id = t.id AND m.created_at <> t.created_at');
        });

        DB::statement('ANALYZE');
        $this->command->info(sprintf('%d items, %d documents, %d movements.', self::ITEMS, $documents, count($lines)));
    }

    /**
     * ITEMS items spread over the customers: Piece with Box of 12; one in five
     * tracks expiry, with three expiry dates.
     *
     * @param  list<string>  $customerIds
     * @return array{0: array<array-key, list<array{id: string}>>, 1: array<array-key, list<string>>}
     */
    private function items(array $customerIds, string $piece, string $box): array
    {
        $products = [];
        $expiries = [];

        for ($i = 1; $i <= self::ITEMS; $i++) {
            $customerId = $customerIds[$i % count($customerIds)];
            $expiry = $i % 5 === 0;

            $id = (string) DB::table('product')->insertGetId([
                'customer_id' => $customerId, 'sku' => sprintf('PERF-%04d', $i), 'name_en' => "Performance item $i",
                'name_ar' => "صنف اختبار $i", 'name_fr' => "Article de test $i", 'base_unit_id' => $piece, 'track_expiry' => $expiry,
            ], 'id');
            DB::table('product_unit')->insert(['product_id' => $id, 'unit_id' => $piece, 'factor_to_base' => 1, 'is_default' => true]);
            DB::table('product_unit')->insert(['product_id' => $id, 'unit_id' => $box, 'factor_to_base' => 12, 'is_default' => false]);

            if ($expiry) {
                foreach ([3, 9, 18] as $months) {
                    $expiries[$id][] = now()->addMonths($months)->toDateString();
                }
            }

            $products[$customerId][] = ['id' => $id];
        }

        return [$products, $expiries];
    }

    /**
     * Saves a posted document header (its movements are posted later) and returns its id.
     */
    private function document(string $type, int $number, string $warehouseId, string $customerId, string $userId, \DateTimeInterface $at): string
    {
        return (string) DB::table('stock_transaction')->insertGetId([
            'doc_no' => config("transactions.types.$type.prefix").'-'.$at->format('Y').'-'.$number,
            'transaction_type' => $type,
            'status' => 'POSTED',
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'reason_code' => $type === 'DAMAGE' ? 'HANDLING' : null,
            'document_date' => $at->format('Y-m-d'),
            'created_by' => $userId,
            'created_at' => $at,
            'posted_at' => $at,
        ], 'id');
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $transactionId, int $lineNo, string $productId, ?string $expiry, string $unitId, int $qty): array
    {
        return [
            'transaction_id' => $transactionId,
            'line_no' => $lineNo,
            'product_id' => $productId,
            'expiry_date' => $expiry,
            'unit_id' => $unitId,
            'qty_entered' => $qty,
            'qty_base' => $qty,
        ];
    }
}
