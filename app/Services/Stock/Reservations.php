<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Services\Stock\Exceptions\SerialProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stock held for Orders and Delivery notes not delivered yet: the only code that
 * reserves or releases it, always through fn_reserve / fn_release_reservations,
 * which keep stock_balance.qty_reserved equal to the stock_reservation rows.
 *
 * An Order reserves its lines when saved (an edit releases and reserves again,
 * a cancel releases), confirming it hands the rows to the Delivery note's lines,
 * and delivering or cancelling the Delivery note releases them. Every call runs
 * inside the caller's DB::transaction (DraftService / PostingService).
 *
 * Reservations are per lot (expiry date + batch) for expiry items, on the one
 * balance of an item without expiry, and per serial (qty 1) for serial items.
 * Balances are locked in item / lot order, so two orders of the same items
 * never wait for each other in opposite orders.
 */
final class Reservations
{
    /**
     * Reserves the lines of an Order. Each line: its draft line id, product,
     * base quantity, lot (null for items without expiry; batch '' = stock
     * without a batch) and serials, plus the form row key to report problems on.
     *
     * @param  list<array{draft_line_id: string, product: Product, qty_base: float, expiry: string|null, batch: string|null, serials: list<string>, key: string}>  $lines
     *
     * @throws Exceptions\InsufficientStock|SerialProblem
     */
    public function reserveOrder(string $warehouseId, string $customerId, array $lines): void
    {
        usort($lines, fn (array $a, array $b) => [$a['product']->id, (string) $a['expiry'], (string) $a['batch']] <=> [$b['product']->id, (string) $b['expiry'], (string) $b['batch']]);

        foreach ($lines as $line) {
            $product = $line['product'];

            if ($product->is_service) {
                continue;
            }

            if ($line['serials'] !== []) {
                $this->reserveSerials($warehouseId, $customerId, $product, $line['serials'], $line['draft_line_id'], $line['key']);

                continue;
            }

            $this->reserve($line['draft_line_id'], $warehouseId, $customerId, $product, $line['expiry'], $line['batch'], $line['qty_base'], $line['key']);
        }
    }

    /**
     * Releases everything an Order holds (edited, cancelled).
     */
    public function releaseOrder(string $draftId): void
    {
        $this->release(DB::table('stock_reservation')
            ->whereIn('draft_line_id', DB::table('stock_draft_line')->where('draft_id', $draftId)->select('id')));
    }

    /**
     * Confirming an Order: its reservations now belong to the Delivery note's
     * lines. Nothing is released, so nobody can take the stock in between.
     *
     * @param  array<string, string>  $lineIds  stock_draft_line.id => stock_transaction_line.id
     */
    public function moveToDeliveryNote(array $lineIds): void
    {
        foreach ($lineIds as $draftLineId => $lineId) {
            DB::table('stock_reservation')
                ->where('draft_line_id', $draftLineId)
                ->update(['draft_line_id' => null, 'line_id' => $lineId]);
        }
    }

    /**
     * Releases everything a Delivery note holds (delivered, cancelled).
     */
    public function releaseDeliveryNote(string $transactionId): void
    {
        $this->release(DB::table('stock_reservation')
            ->whereIn('line_id', DB::table('stock_transaction_line')->where('transaction_id', $transactionId)->select('id')));
    }

    /**
     * Base quantity reserved on one balance (item, and lot or none).
     */
    public function reservedOn(string $warehouseId, string $customerId, string $productId, ?string $expiry, ?string $batch): float
    {
        return (float) DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('expiry_key', $expiry ?? '0001-01-01')
            ->where('batch_key', (string) $batch)
            ->value('qty_reserved');
    }

    /**
     * Who holds stock of an item here, for the Stock page: each Order / Delivery
     * note with its reference and quantity (base units), oldest first.
     *
     * @return list<array{reference: string, type: string, draft: bool, qty: float}>
     */
    public function holders(string $warehouseId, string $customerId, string $productId): array
    {
        $orders = DB::table('stock_reservation as r')
            ->join('stock_draft_line as dl', 'dl.id', '=', 'r.draft_line_id')
            ->join('stock_draft as d', 'd.id', '=', 'dl.draft_id')
            ->where('r.warehouse_id', $warehouseId)->where('r.customer_id', $customerId)->where('r.product_id', $productId)
            ->groupBy('d.id', 'd.doc_no', 'd.transaction_type', 'd.created_at')
            ->get(['d.doc_no as reference', 'd.transaction_type as type', 'd.created_at', DB::raw('sum(r.qty_base) as qty'), DB::raw('true as draft')]);

        $notes = DB::table('stock_reservation as r')
            ->join('stock_transaction_line as l', 'l.id', '=', 'r.line_id')
            ->join('stock_transaction as t', 't.id', '=', 'l.transaction_id')
            ->where('r.warehouse_id', $warehouseId)->where('r.customer_id', $customerId)->where('r.product_id', $productId)
            ->groupBy('t.id', 't.doc_no', 't.transaction_type', 't.created_at')
            ->get(['t.doc_no as reference', 't.transaction_type as type', 't.created_at', DB::raw('sum(r.qty_base) as qty'), DB::raw('false as draft')]);

        return $orders->concat($notes)
            ->sortBy('created_at')
            ->map(fn ($row) => ['reference' => $row->reference, 'type' => $row->type, 'draft' => (bool) $row->draft, 'qty' => (float) $row->qty])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $serialIds  one reservation each (qty 1), for serial items
     */
    private function reserve(string $draftLineId, string $warehouseId, string $customerId, Product $product, ?string $expiry, ?string $batch, float $qty, string $key, array $serialIds = []): void
    {
        try {
            // In a savepoint: when it is refused, the message below still needs the database.
            DB::transaction(fn () => DB::select('SELECT fn_reserve(?, NULL, ?, ?, ?, ?::date, ?, ?::bigint[], ?)', [
                $draftLineId, $warehouseId, $customerId, $product->id, $expiry, $batch === null || $batch === '' ? null : $batch,
                $serialIds === [] ? null : '{'.implode(',', $serialIds).'}', $qty,
            ]));
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Reserved stock exceeded')) {
                throw $e;
            }

            preg_match('/available=(-?[0-9.]+)/', $e->getMessage(), $match);

            throw Availability::notEnough($product, $warehouseId, (float) ($match[1] ?? 0), $qty, row: $key);
        }
    }

    /**
     * One reservation per serial: each must be in stock here and not held by
     * another Order / Delivery note.
     *
     * @param  list<string>  $serials
     */
    private function reserveSerials(string $warehouseId, string $customerId, Product $product, array $serials, string $draftLineId, string $key): void
    {
        $rows = DB::table('serial_number')
            ->where('product_id', $product->id)
            ->whereIn('serial_no', $serials)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'serial_no', 'status', 'warehouse_id'])
            ->keyBy('serial_no');

        $held = app(Availability::class)->reservedSerials($product->id, $serials);
        $ids = [];

        foreach ($serials as $serial) {
            $row = $rows->get($serial);

            if ($row === null || $row->status !== 'IN_STOCK' || $row->warehouse_id !== $warehouseId) {
                throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $serial], $serial, $key);
            }

            if (in_array($serial, $held, true)) {
                throw new SerialProblem('stock.errors.serial_reserved', ['serial' => $serial], $serial, $key);
            }

            $ids[] = (string) $row->id;
        }

        // All the line's serials in one call.
        $this->reserve($draftLineId, $warehouseId, $customerId, $product, null, null, (float) count($ids), $key, $ids);
    }

    /**
     * Releases the reservations a query selects, all in one call.
     *
     * @param  Builder  $query
     */
    private function release($query): void
    {
        $ids = $query->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($ids !== []) {
            DB::select('SELECT fn_release_reservations(?::bigint[])', ['{'.implode(',', $ids).'}']);
        }
    }
}
