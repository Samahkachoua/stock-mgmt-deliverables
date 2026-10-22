<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\OnlyExpiredStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 *
 * Stock going out on a document dated before today ($date) is limited to what
 * was here on that day: see onDate().
 */
class Availability
{
    /**
     * Today in the warehouse: every warehouse works in the company's time zone
     * (Settings › General). Balance rows of items without expiry have expiry_date
     * NULL (stock_balance.expiry_key sentinel).
     */
    public function today(string $warehouseId): CarbonImmutable
    {
        return CompanyTime::today();
    }

    /**
     * Base quantity in stock. With $excludeExpired, expired stock doesn't count.
     */
    public function quantity(string $warehouseId, string $customerId, string $productId, bool $excludeExpired = false, ?CarbonInterface $date = null): float
    {
        return array_sum(array_map(
            fn (array $row) => $excludeExpired && $row['expired'] ? 0.0 : $row['qty'],
            $this->rows($warehouseId, $customerId, $productId, $date),
        ));
    }

    /**
     * Stock per expiry date (a single row with expiry null for items without
     * expiry), nearest expiry first (FEFO). With a $date before today, only
     * what may go out on that date (see onDate()).
     *
     * @return list<array{expiry: string|null, qty: float, expired: bool}>
     */
    public function rows(string $warehouseId, string $customerId, string $productId, ?CarbonInterface $date = null): array
    {
        $today = $this->today($warehouseId)->toDateString();
        $then = $this->isBackdated($warehouseId, $date) ? $this->onDate($warehouseId, $customerId, $productId, $date) : null;

        return DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('qty_base', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST')
            ->get(['expiry_date', 'qty_base'])
            ->map(fn ($row) => [
                'expiry' => $row->expiry_date,
                'qty' => $then === null ? (float) $row->qty_base : min((float) $row->qty_base, $then[$row->expiry_date ?? ''] ?? 0.0),
                'expired' => $row->expiry_date !== null && $row->expiry_date < $today,
            ])
            ->filter(fn (array $row) => $row['qty'] > 0)
            ->values()
            ->all();
    }

    /**
     * Whether a document dated $date is dated before today in this warehouse.
     */
    public function isBackdated(string $warehouseId, ?CarbonInterface $date): bool
    {
        return $date !== null && $date->toDateString() < $this->today($warehouseId)->toDateString();
    }

    /**
     * What may go out on a document dated $date, per expiry date ('' for items
     * without expiry): what was here at the end of that day, but never more than
     * the lowest stock of any later day, so no later day goes below zero.
     * Example: nothing until 21 Oct, 10 received on 21 Oct: an Issue dated 1 Oct
     * may take nothing.
     *
     * A movement counts on its document's date; stock a transfer brings in
     * counts on the day it was received (in this warehouse's time zone).
     *
     * @return array<string, float>
     */
    public function onDate(string $warehouseId, string $customerId, string $productId, CarbonInterface $date): array
    {
        $movementDay = self::movementDay();

        $rows = DB::select(<<<SQL
            WITH daily AS (
                SELECT m.expiry_date, {$movementDay} AS day,
                       SUM(CASE WHEN m.direction = 'IN' THEN m.qty_base ELSE -m.qty_base END) AS delta
                FROM stock_movement m
                JOIN stock_transaction t ON t.id = m.transaction_id
                WHERE m.warehouse_id = ? AND m.customer_id = ? AND m.product_id = ?
                GROUP BY 1, 2
            ), running AS (
                SELECT expiry_date, day, SUM(delta) OVER (PARTITION BY expiry_date ORDER BY day) AS balance
                FROM daily
            )
            SELECT expiry_date,
                   COALESCE((array_agg(balance ORDER BY day DESC) FILTER (WHERE day <= ?::date))[1], 0) AS on_day,
                   MIN(balance) FILTER (WHERE day > ?::date) AS later
            FROM running
            GROUP BY expiry_date
        SQL, [$warehouseId, $customerId, $productId, $date->toDateString(), $date->toDateString()]);

        $quantities = [];

        foreach ($rows as $row) {
            $qty = $row->later === null ? (float) $row->on_day : min((float) $row->on_day, (float) $row->later);
            $quantities[$row->expiry_date ?? ''] = max(round($qty, 4), 0.0);
        }

        return $quantities;
    }

    /**
     * Of $balance (one expiry date, or none), what may go out on a document
     * dated $date: all of it when it is dated today (or no date is given).
     */
    public function usable(float $balance, string $warehouseId, string $customerId, string $productId, ?string $expiry, ?CarbonInterface $date): float
    {
        if (! $this->isBackdated($warehouseId, $date)) {
            return $balance;
        }

        return min($balance, $this->onDate($warehouseId, $customerId, $productId, $date)[$expiry ?? ''] ?? 0.0);
    }

    /**
     * Of these serial numbers in stock here, those that were already here on
     * $date: none of them arrived (again) after it.
     *
     * @param  list<string>  $serials
     * @return list<string>
     */
    public function serialsOnDate(string $warehouseId, string $productId, array $serials, ?CarbonInterface $date): array
    {
        if ($serials === [] || ! $this->isBackdated($warehouseId, $date)) {
            return $serials;
        }

        $later = DB::table('serial_number as s')
            ->join('stock_transaction_line_serial as ls', 'ls.serial_id', '=', 's.id')
            ->join('stock_movement as m', fn ($join) => $join->on('m.line_id', '=', 'ls.line_id')->on('m.warehouse_id', '=', 's.warehouse_id'))
            ->join('stock_transaction as t', 't.id', '=', 'm.transaction_id')
            ->where('s.product_id', $productId)
            ->where('s.warehouse_id', $warehouseId)
            ->whereIn('s.serial_no', $serials)
            ->where('m.direction', 'IN')
            ->whereRaw(self::movementDay().' > ?::date', [$date->toDateString()])
            ->distinct()
            ->pluck('s.serial_no')
            ->all();

        return array_values(array_diff($serials, $later));
    }

    /**
     * The day a movement counts on, in SQL: its document's date, or for stock a
     * transfer brings in, the day it was received (in the company's time zone).
     * $movement and $transaction are the aliases of stock_movement and its
     * stock_transaction in the query. Used by the stock card too.
     */
    public static function movementDay(string $movement = 'm', string $transaction = 't'): string
    {
        $zone = DB::getPdo()->quote(CompanyTime::zone());

        return "CASE WHEN {$movement}.warehouse_id = {$transaction}.to_warehouse_id AND {$transaction}.received_at IS NOT NULL"
            ." THEN ({$transaction}.received_at AT TIME ZONE {$zone})::date ELSE {$transaction}.document_date END";
    }

    /**
     * Serial numbers of the item that are in stock in this warehouse (with a
     * $date before today: those already here on that date).
     *
     * @return list<string>
     */
    public function serials(string $warehouseId, string $productId, ?CarbonInterface $date = null): array
    {
        $serials = DB::table('serial_number')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'IN_STOCK')
            ->orderBy('serial_no')
            ->pluck('serial_no')
            ->all();

        return $this->serialsOnDate($warehouseId, $productId, $serials, $date);
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
     * $useExpired; with a $date before today, only what was here on that date
     * is used. Throws when there is not enough; the exception says how much
     * there is, and how much of the rest has expired.
     *
     * @return list<array{expiry: string, qty: float}>
     */
    public function allocate(Product $product, string $warehouseId, string $customerId, float $needed, bool $useExpired, ?string $row = null, ?CarbonInterface $date = null): array
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

        $then = $this->isBackdated($warehouseId, $date) ? $this->onDate($warehouseId, $customerId, $product->id, $date) : null;
        $allocations = [];
        $remaining = $needed;
        $usable = 0.0;
        $expired = 0.0;

        foreach ($lots as $lot) {
            $qty = $then === null ? (float) $lot->qty_base : min((float) $lot->qty_base, $then[$lot->expiry_date] ?? 0.0);

            if (! $useExpired && $lot->expiry_date < $today) {
                $expired += $qty;

                continue;
            }

            $usable += $qty;

            if ($remaining > 0 && $qty > 0) {
                $take = min($qty, $remaining);
                $allocations[] = ['expiry' => $lot->expiry_date, 'qty' => round($take, 4)];
                $remaining = round($remaining - $take, 4);
            }
        }

        if ($remaining > 0) {
            throw self::notEnough($product, $warehouseId, $usable, $needed, $expired, $row, $then === null ? null : $date);
        }

        return $allocations;
    }

    /**
     * The expiry date the user chose (Y-m-d), locked: there must be $needed
     * base units of it here (on $date too, when the document is dated before
     * today), and it must not have expired unless $useExpired.
     */
    public function takeExpiry(Product $product, string $warehouseId, string $customerId, string $expiry, float $needed, bool $useExpired, ?string $row = null, ?CarbonInterface $date = null): void
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

        $then = $this->usable($available, $warehouseId, $customerId, $product->id, $expiry, $date);

        if ($then < $needed - 1e-9) {
            throw self::notEnough($product, $warehouseId, $then, $needed, row: $row, date: $date);
        }
    }

    /**
     * "Only 5 Box + 4 Piece of Canned Beans available in Beirut Main." — or, when
     * the rest has expired, says so (OnlyExpiredStock when nothing usable is left).
     * With a $date (a document dated before today): what there was on that date.
     */
    public static function notEnough(Product $product, string $warehouseId, float $available, float $needed, float $expired = 0, ?string $row = null, ?CarbonInterface $date = null): InsufficientStock
    {
        $converter = $product->converter();
        $params = [
            'qty' => $converter->breakdown($available),
            'item' => $product->name(),
            'warehouse' => (string) DB::table('warehouse')->where('id', $warehouseId)->value('name'),
        ];

        if ($date !== null) {
            return new InsufficientStock('stock.errors.not_enough_on_date', $params + ['date' => Format::date($date)], $available, $needed, $expired, $row);
        }

        if ($available <= 0 && $expired > 0) {
            return new OnlyExpiredStock('stock.errors.only_expired', ['qty' => $converter->breakdown($expired)] + $params, $available, $needed, $expired, $row);
        }

        return $expired > 0
            ? new InsufficientStock('stock.errors.not_enough_with_expired', $params + ['expired' => $converter->breakdown($expired)], $available, $needed, $expired, $row)
            : new InsufficientStock('stock.errors.not_enough', $params, $available, $needed, $expired, $row);
    }
}
