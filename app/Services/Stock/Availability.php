<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\OnlyExpiredStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is in stock, read from the stock_balance cache (never written here).
 *
 * Stock of an expiry item is kept per expiry date. "Expired" means that date
 * is before today in the warehouse's own time zone: stock can still go out on
 * its expiry date.
 *
 * Methods named lock...() / allocate() / takeExpiry() take row locks (SELECT ... FOR UPDATE)
 * and must run inside the posting transaction, so the quantities they return
 * cannot change before the movements are written.
 */
class Availability
{
    /** Balance rows of items without expiry have expiry_date NULL (stock_balance.expiry_key sentinel). */
    public function today(string $warehouseId): CarbonImmutable
    {
        $timezone = DB::table('warehouse')->where('id', $warehouseId)->value('timezone') ?: config('app.timezone');

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    /**
     * Base quantity in stock. With $excludeExpired, expired stock doesn't count.
     */
    public function quantity(string $warehouseId, string $customerId, string $productId, bool $excludeExpired = false): float
    {
        return array_sum(array_map(
            fn (array $row) => $excludeExpired && $row['expired'] ? 0.0 : $row['qty'],
            $this->rows($warehouseId, $customerId, $productId),
        ));
    }

    /**
     * Stock per expiry date (a single row with expiry null for items without
     * expiry), nearest expiry first (FEFO).
     *
     * @return list<array{expiry: string|null, qty: float, expired: bool}>
     */
    public function rows(string $warehouseId, string $customerId, string $productId): array
    {
        $today = $this->today($warehouseId)->toDateString();

        return DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('qty_base', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST')
            ->get(['expiry_date', 'qty_base'])
            ->map(fn ($row) => [
                'expiry' => $row->expiry_date,
                'qty' => (float) $row->qty_base,
                'expired' => $row->expiry_date !== null && $row->expiry_date < $today,
            ])
            ->values()
            ->all();
    }

    /**
     * Serial numbers of the item that are in stock in this warehouse.
     *
     * @return list<string>
     */
    public function serials(string $warehouseId, string $productId): array
    {
        return DB::table('serial_number')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'IN_STOCK')
            ->orderBy('serial_no')
            ->pluck('serial_no')
            ->all();
    }

    /**
     * Locks the balance of an item without expiry and returns it.
     */
    public function lockNoExpiry(string $warehouseId, string $customerId, string $productId): float
    {
        $qty = DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->whereNull('expiry_date')
            ->lockForUpdate()
            ->value('qty_base');

        return (float) ($qty ?? 0);
    }

    /**
     * Picks stock nearest expiry first (FEFO) to cover $needed base units,
     * locking every balance it reads. Expired stock is skipped unless
     * $useExpired. Throws when there is not enough; the exception says how much
     * there is, and how much of the rest has expired.
     *
     * @return list<array{expiry: string, qty: float}>
     */
    public function allocate(Product $product, string $warehouseId, string $customerId, float $needed, bool $useExpired, ?string $row = null): array
    {
        $today = $this->today($warehouseId)->toDateString();

        $lots = DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $product->id)
            ->whereNotNull('expiry_date')
            ->where('qty_base', '>', 0)
            ->orderBy('expiry_date')
            ->lockForUpdate()
            ->get(['expiry_date', 'qty_base']);

        $allocations = [];
        $remaining = $needed;
        $usable = 0.0;
        $expired = 0.0;

        foreach ($lots as $lot) {
            $qty = (float) $lot->qty_base;

            if (! $useExpired && $lot->expiry_date < $today) {
                $expired += $qty;

                continue;
            }

            $usable += $qty;

            if ($remaining > 0) {
                $take = min($qty, $remaining);
                $allocations[] = ['expiry' => $lot->expiry_date, 'qty' => round($take, 4)];
                $remaining = round($remaining - $take, 4);
            }
        }

        if ($remaining > 0) {
            throw self::notEnough($product, $warehouseId, $usable, $needed, $expired, $row);
        }

        return $allocations;
    }

    /**
     * The expiry date the user chose (Y-m-d), locked: there must be $needed
     * base units of it here, and it must not have expired unless $useExpired.
     */
    public function takeExpiry(Product $product, string $warehouseId, string $customerId, string $expiry, float $needed, bool $useExpired, ?string $row = null): void
    {
        if (! $useExpired && $expiry < $this->today($warehouseId)->toDateString()) {
            throw new PostingRejected('stock.errors.expiry_expired', ['date' => Format::date(CarbonImmutable::parse($expiry))], $row, 'expiry');
        }

        $available = (float) (DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $product->id)
            ->where('expiry_date', $expiry)
            ->lockForUpdate()
            ->value('qty_base') ?? 0);

        if ($available <= 0) {
            throw new PostingRejected('transactions.form.errors.expiry_not_here', [], $row, 'expiry');
        }

        if ($available < $needed - 1e-9) {
            throw self::notEnough($product, $warehouseId, $available, $needed, row: $row);
        }
    }

    /**
     * "Only 5 Box + 4 Piece of Canned Beans available in Beirut Main." — or, when
     * the rest has expired, says so (OnlyExpiredStock when nothing usable is left).
     */
    public static function notEnough(Product $product, string $warehouseId, float $available, float $needed, float $expired = 0, ?string $row = null): InsufficientStock
    {
        $converter = $product->converter();
        $params = [
            'qty' => $converter->breakdown($available),
            'item' => $product->name(),
            'warehouse' => (string) DB::table('warehouse')->where('id', $warehouseId)->value('name'),
        ];

        if ($available <= 0 && $expired > 0) {
            return new OnlyExpiredStock('stock.errors.only_expired', ['qty' => $converter->breakdown($expired)] + $params, $available, $needed, $expired, $row);
        }

        return $expired > 0
            ? new InsufficientStock('stock.errors.not_enough_with_expired', $params + ['expired' => $converter->breakdown($expired)], $available, $needed, $expired, $row)
            : new InsufficientStock('stock.errors.not_enough', $params, $available, $needed, $expired, $row);
    }
}
