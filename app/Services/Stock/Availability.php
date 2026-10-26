<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\OnlyExpiredStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Support\Batches;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What is in stock, read from the stock_balance cache (never written here).
 *
 * Stock of an expiry item is kept per lot: expiry date + batch number
 * (CLAUDE.md rule 9; batch '' = stock from before batches). "Expired" means the
 * date is before today in the company's time zone: stock can still go out on
 * its expiry date.
 *
 * Methods named lock...() / allocate() / takeLot() take row locks (SELECT ... FOR UPDATE)
 * and must run inside the posting transaction, so the quantities they return
 * cannot change before the movements are written.
 *
 * Stock going out on a document dated before today ($date) is limited to what
 * was here on that day: see onDate().
 *
 * Stock reserved for Orders / Delivery notes not delivered yet
 * (stock_balance.qty_reserved, see Reservations) is not available to anything
 * else: every quantity here that may be taken is qty_base - qty_reserved.
 * Counts compare with the whole balance (PostingService::prepareCount()).
 */
class Availability
{
    /**
     * Today in the warehouse: every warehouse works in the company's time zone
     * (Settings › General). Balance rows of items without expiry have expiry_date
     * and batch_no NULL (stock_balance.expiry_key / batch_key sentinels).
     */
    public function today(string $warehouseId): CarbonImmutable
    {
        return CompanyTime::today();
    }

    /**
     * Base quantity available (not reserved). With $excludeExpired, expired stock doesn't count.
     */
    public function quantity(string $warehouseId, string $customerId, string $productId, bool $excludeExpired = false, ?CarbonInterface $date = null): float
    {
        return array_sum(array_map(
            fn (array $row) => $excludeExpired && $row['expired'] ? 0.0 : $row['qty'],
            $this->rows($warehouseId, $customerId, $productId, $date),
        ));
    }

    /**
     * Stock per lot (a single row with expiry and batch null for items without
     * expiry), nearest expiry first (FEFO), then stock without a batch, then by
     * batch. `qty` is what is available (not reserved), `reserved` what is held
     * for orders; a lot all reserved is listed with qty 0. With a $date before
     * today, only what may go out on that date (see onDate()).
     *
     * @return list<array{expiry: string|null, batch: string, lot: string, qty: float, reserved: float, expired: bool}>
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
            ->orderByRaw('expiry_date ASC NULLS LAST, batch_key ASC')
            ->get(['expiry_date', 'batch_key', 'qty_base', 'qty_reserved'])
            ->map(function ($row) use ($then, $today) {
                $lot = Batches::key($row->expiry_date, $row->batch_key);
                $free = (float) $row->qty_base - (float) $row->qty_reserved;

                return [
                    'expiry' => $row->expiry_date,
                    'batch' => (string) $row->batch_key,
                    'lot' => $lot,
                    'qty' => $then === null ? $free : max(min($free, $then[$lot] ?? 0.0), 0.0),
                    'reserved' => (float) $row->qty_reserved,
                    'expired' => $row->expiry_date !== null && $row->expiry_date < $today,
                ];
            })
            ->filter(fn (array $row) => $row['qty'] > 0 || $row['reserved'] > 0)
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
     * What may go out on a document dated $date, per lot (Batches::key(); "|" for
     * items without expiry): what was here at the end of that day, but never more
     * than the lowest stock of any later day, so no later day goes below zero.
     * Example: nothing until 21 Oct, 10 received on 21 Oct: a Delivery note dated 1 Oct
     * may take nothing.
     *
     * A movement counts on its document's date; stock a transfer brings in
     * counts on the day it was received (in this warehouse's time zone).
     *
     * @return array<string, float>
     */
    public function onDate(string $warehouseId, string $customerId, string $productId, CarbonInterface $date): array
    {
        return $this->onDateForItems($warehouseId, $customerId, [$productId], $date)[$productId] ?? [];
    }

    /**
     * onDate() for several items in one query, by product id (an item with no
     * movements here is left out).
     *
     * @param  list<string>  $productIds
     * @return array<string, array<string, float>>
     */
    public function onDateForItems(string $warehouseId, string $customerId, array $productIds, CarbonInterface $date): array
    {
        if ($productIds === []) {
            return [];
        }

        $movementDay = self::movementDay();
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

        $rows = DB::select(<<<SQL
            WITH daily AS (
                SELECT m.product_id, m.expiry_date, COALESCE(m.batch_no, '') AS batch, {$movementDay} AS day,
                       SUM(CASE WHEN m.direction = 'IN' THEN m.qty_base ELSE -m.qty_base END) AS delta
                FROM stock_movement m
                JOIN stock_transaction t ON t.id = m.transaction_id
                WHERE m.warehouse_id = ? AND m.customer_id = ? AND m.product_id IN ({$placeholders})
                GROUP BY 1, 2, 3, 4
            ), running AS (
                SELECT product_id, expiry_date, batch, day,
                       SUM(delta) OVER (PARTITION BY product_id, expiry_date, batch ORDER BY day) AS balance
                FROM daily
            )
            SELECT product_id, expiry_date, batch,
                   COALESCE((array_agg(balance ORDER BY day DESC) FILTER (WHERE day <= ?::date))[1], 0) AS on_day,
                   MIN(balance) FILTER (WHERE day > ?::date) AS later
            FROM running
            GROUP BY product_id, expiry_date, batch
        SQL, [$warehouseId, $customerId, ...$productIds, $date->toDateString(), $date->toDateString()]);

        $quantities = [];

        foreach ($rows as $row) {
            $qty = $row->later === null ? (float) $row->on_day : min((float) $row->on_day, (float) $row->later);
            $quantities[(string) $row->product_id][Batches::key($row->expiry_date, $row->batch)] = max(round($qty, 4), 0.0);
        }

        return $quantities;
    }

    /**
     * Of $balance (one lot, or an item without expiry when $expiry is null),
     * what may go out on a document dated $date: all of it when it is dated
     * today (or no date is given).
     */
    public function usable(float $balance, string $warehouseId, string $customerId, string $productId, ?string $expiry, ?CarbonInterface $date, ?string $batch = null): float
    {
        if (! $this->isBackdated($warehouseId, $date)) {
            return $balance;
        }

        return min($balance, $this->onDate($warehouseId, $customerId, $productId, $date)[Batches::key($expiry, $batch)] ?? 0.0);
    }

    /**
     * The batches of an item in stock here on one expiry date ('' = stock
     * without a batch), in FEFO order: with $available, only those with stock
     * that is not reserved (taking stock); otherwise all (counting).
     *
     * @return list<string>
     */
    public function batchesOn(string $warehouseId, string $customerId, string $productId, string $expiry, bool $available = true): array
    {
        return DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('expiry_date', $expiry)
            ->when($available, fn ($query) => $query->whereColumn('qty_base', '>', 'qty_reserved'), fn ($query) => $query->where('qty_base', '>', 0))
            ->orderBy('batch_key')
            ->pluck('batch_key')
            ->map(fn ($batch) => (string) $batch)
            ->all();
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
     * The day a movement counts on, in SQL: its document's date; for stock a
     * transfer brings in, the day it was received; for a delivery note, the day
     * it was delivered (in the company's time zone). $movement and $transaction
     * are the aliases of stock_movement and its stock_transaction in the query.
     * Used by the stock card too.
     */
    public static function movementDay(string $movement = 'm', string $transaction = 't'): string
    {
        $zone = DB::getPdo()->quote(CompanyTime::zone());

        return "CASE WHEN {$movement}.warehouse_id = {$transaction}.to_warehouse_id AND {$transaction}.received_at IS NOT NULL"
            ." THEN ({$transaction}.received_at AT TIME ZONE {$zone})::date"
            ." WHEN {$transaction}.delivered_at IS NOT NULL THEN ({$transaction}.delivered_at AT TIME ZONE {$zone})::date"
            ." ELSE {$transaction}.document_date END";
    }

    /**
     * Serial numbers of the item that are in stock in this warehouse and not
     * held by an Order / Delivery note (those of the Order $ownDraftId still
     * count: they are its own; with $withReserved, all of them: a count finds
     * them on the shelf too). With a $date before today: those already here
     * on that date.
     *
     * @return list<string>
     */
    public function serials(string $warehouseId, string $productId, ?CarbonInterface $date = null, ?string $ownDraftId = null, bool $withReserved = false): array
    {
        $serials = DB::table('serial_number as s')
            ->where('s.product_id', $productId)
            ->where('s.warehouse_id', $warehouseId)
            ->where('s.status', 'IN_STOCK')
            ->when(! $withReserved, fn ($query) => $query->whereNotExists(fn ($query) => $query->from('stock_reservation as r')
                ->whereColumn('r.serial_id', 's.id')
                ->when($ownDraftId !== null, fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('r.draft_line_id')
                    ->orWhereNotIn('r.draft_line_id', DB::table('stock_draft_line')->where('draft_id', $ownDraftId)->select('id'))))))
            ->orderBy('s.serial_no')
            ->pluck('s.serial_no')
            ->all();

        return $this->serialsOnDate($warehouseId, $productId, $serials, $date);
    }

    /**
     * Of these serial numbers of the item, those held by an Order / Delivery note.
     *
     * @param  list<string>  $serials
     * @return list<string>
     */
    public function reservedSerials(string $productId, array $serials): array
    {
        if ($serials === []) {
            return [];
        }

        return DB::table('stock_reservation as r')
            ->join('serial_number as s', 's.id', '=', 'r.serial_id')
            ->where('s.product_id', $productId)
            ->whereIn('s.serial_no', $serials)
            ->pluck('s.serial_no')
            ->all();
    }

    /**
     * Locks the balance of an item without expiry and returns what is available (not reserved).
     */
    public function lockNoExpiry(string $warehouseId, string $customerId, string $productId): float
    {
        $row = DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->whereNull('expiry_date')
            ->lockForUpdate()
            ->first(['qty_base', 'qty_reserved']);

        return $row === null ? 0.0 : (float) $row->qty_base - (float) $row->qty_reserved;
    }

    /**
     * Picks stock nearest expiry first (FEFO) to cover $needed base units,
     * locking every balance it reads: lot by lot (expiry date, then stock
     * without a batch, then batch). Expired stock is skipped unless $useExpired;
     * with a $date before today, only what was here on that date is used.
     * Throws when there is not enough; the exception says how much there is,
     * and how much of the rest has expired.
     *
     * @return list<array{expiry: string, batch: string, qty: float}>
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
            ->orderBy('batch_key')
            ->lockForUpdate()
            ->get(['expiry_date', 'batch_key', 'qty_base', 'qty_reserved']);

        $then = $this->isBackdated($warehouseId, $date) ? $this->onDate($warehouseId, $customerId, $product->id, $date) : null;
        $allocations = [];
        $remaining = $needed;
        $usable = 0.0;
        $expired = 0.0;

        foreach ($lots as $lot) {
            $batch = (string) $lot->batch_key;
            // Reserved stock is never taken.
            $free = (float) $lot->qty_base - (float) $lot->qty_reserved;
            $qty = max($then === null ? $free : min($free, $then[Batches::key($lot->expiry_date, $batch)] ?? 0.0), 0.0);

            if (! $useExpired && $lot->expiry_date < $today) {
                $expired += $qty;

                continue;
            }

            $usable += $qty;

            if ($remaining > 0 && $qty > 0) {
                $take = min($qty, $remaining);
                $allocations[] = ['expiry' => $lot->expiry_date, 'batch' => $batch, 'qty' => round($take, 4)];
                $remaining = round($remaining - $take, 4);
            }
        }

        if ($remaining > 0) {
            throw self::notEnough($product, $warehouseId, $usable, $needed, $expired, $row, $then === null ? null : $date);
        }

        return $allocations;
    }

    /**
     * The lot the user chose (expiry Y-m-d and batch, '' = without a batch),
     * locked: there must be $needed base units of it here (on $date too, when
     * the document is dated before today), and it must not have expired unless
     * $useExpired.
     */
    public function takeLot(Product $product, string $warehouseId, string $customerId, string $expiry, string $batch, float $needed, bool $useExpired, ?string $row = null, ?CarbonInterface $date = null): void
    {
        if (! $useExpired && $expiry < $this->today($warehouseId)->toDateString()) {
            throw new PostingRejected('stock.errors.expiry_expired', ['date' => Format::date(CarbonImmutable::parse($expiry))], $row, 'expiry');
        }

        $balance = DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $product->id)
            ->where('expiry_date', $expiry)
            ->where('batch_key', $batch)
            ->lockForUpdate()
            ->first(['qty_base', 'qty_reserved']);

        if ($balance === null || (float) $balance->qty_base <= 0) {
            throw new PostingRejected('transactions.form.errors.expiry_not_here', [], $row, 'expiry');
        }

        // Reserved stock is never taken.
        $available = (float) $balance->qty_base - (float) $balance->qty_reserved;

        if ($available < $needed - 1e-9) {
            throw self::notEnough($product, $warehouseId, $available, $needed, row: $row);
        }

        $then = $this->usable($available, $warehouseId, $customerId, $product->id, $expiry, $date, $batch);

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
        $available = max($available, 0.0);
        $params = [
            'qty' => $converter->breakdown($available),
            'item' => $product->name(),
            'warehouse' => (string) DB::table('warehouse')->where('id', $warehouseId)->value('name'),
        ];

        if ($date !== null) {
            return new InsufficientStock('stock.errors.not_enough_on_date', $params + ['date' => Format::date($date)], $available, $needed, $expired, $row);
        }

        // Part of the stock is held for orders: say so, or the user wonders where it went.
        $reserved = (float) DB::table('stock_balance')->where('warehouse_id', $warehouseId)->where('product_id', $product->id)->sum('qty_reserved');

        if ($reserved > 0 && $expired <= 0) {
            return new InsufficientStock('stock.errors.not_enough_reserved', $params + ['reserved' => $converter->breakdown($reserved)], $available, $needed, $expired, $row);
        }

        if ($available <= 0 && $expired > 0) {
            return new OnlyExpiredStock('stock.errors.only_expired', ['qty' => $converter->breakdown($expired)] + $params, $available, $needed, $expired, $row);
        }

        return $expired > 0
            ? new InsufficientStock('stock.errors.not_enough_with_expired', $params + ['expired' => $converter->breakdown($expired)], $available, $needed, $expired, $row)
            : new InsufficientStock('stock.errors.not_enough', $params, $available, $needed, $expired, $row);
    }
}
