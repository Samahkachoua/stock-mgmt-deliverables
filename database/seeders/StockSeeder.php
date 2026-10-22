<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use App\Support\CompanyTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo stock for local development, posted as Opening Balances through
 * PostingService (the only way stock may change). Includes stock that
 * expires soon and stock that has expired, and serial-numbered machines.
 * Runs once: skipped when Beirut Main already has an opening balance.
 */
class StockSeeder extends Seeder
{
    /**
     * customer code => [item code, unit, qty, expiry offset in days, serials]
     */
    private const OPENING = [
        'ACME' => [
            ['CB-400', 'BOX', 20, 200],
            ['OO-1L', 'CTN', 10, 120],
            ['RC-5K', 'BAG', 45],
            ['TP-200', 'CTN', 8, 300],
            ['YG-500', 'BOX', 6, 4],
            ['YG-500', 'BOX', 10, 30],
            ['YG-500', 'PC', 12, -3],
            ['MK-1L', 'CTN', 12, 60],
            ['SG-1K', 'BAG', 140],
            ['CF-BN', 'BAG', 4],
        ],
        'CEDAR' => [
            ['CT-T12', 'CTN', 8],
            ['WL-SC', 'BOX', 9],
            ['ES-MCH', 'PC', 3, null, ['EM-2026-0001', 'EM-2026-0002', 'EM-2026-0003']],
            ['DS-5L', 'CTN', 16],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('StockSeeder must never run in production.');
        }

        $warehouse = Warehouse::where('code', 'BEY')->firstOrFail();

        if (DB::table('stock_transaction')->where('warehouse_id', $warehouse->id)->where('transaction_type', 'OPENING_BALANCE')->exists()) {
            return;
        }

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $posting = app(PostingService::class);
        $today = CompanyTime::today();

        foreach (self::OPENING as $customerCode => $lines) {
            $customer = Customer::where('code', $customerCode)->firstOrFail();
            $products = Product::where('customer_id', $customer->id)->pluck('id', 'sku');
            $document = [];

            foreach ($lines as $i => $line) {
                $document['r'.($i + 1)] = new DocumentLine(
                    productId: $products[$line[0]],
                    unit: $line[1],
                    qty: $line[2],
                    expiry: isset($line[3]) ? $today->addDays($line[3])->toDateString() : null,
                    serials: $line[4] ?? [],
                );
            }

            $posting->post(new Document('OPENING_BALANCE', $warehouse->id, $customer->id, $today, 'Stock count at go-live', $document), $admin);
        }
    }
}
