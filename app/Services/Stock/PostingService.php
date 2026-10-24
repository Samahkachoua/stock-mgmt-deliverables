<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\Exceptions\SelfApproval;
use App\Services\Stock\Exceptions\SerialProblem;
use App\Services\Stock\Exceptions\StaleCount;
use App\Services\Stock\Exceptions\StockAlreadyUsed;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Audit;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that changes stock (CLAUDE.md rule 1).
 *
 * post() saves a document of a type that posts at once (Opening Balance,
 * Receive, Delivery note: TypeUi::postsDirectly()) as POSTED and writes its movements;
 * dispatches a Transfer (receive() completes it); and saves a Damage /
 * Adjustment either POSTED or, at its approval limit, PENDING_APPROVAL with no
 * movements until approve() (or reject()) — unless its creator may approve
 * that type: then it is posted at once. cancel() reverses a posted
 * document. confirmDraft() turns a draft receipt / order into one posted
 * document in its warehouse. Each runs in one DB::transaction: the
 * reference number, header, lines, serials, movements and audit entry are
 * saved together or not at all. Every movement goes through fn_post_movement,
 * which updates stock_balance and refuses negative stock.
 *
 * A document that carries a request id (the form's submission token) is saved
 * once: post() with the same id again returns the transaction already created
 * (CLAUDE.md "Loading states": double submit is impossible).
 *
 * Quantities are checked here first, against balances locked FOR UPDATE, so the
 * user gets a InsufficientStock with the available quantity; if the database still
 * refuses ("Negative stock blocked"), that error becomes a InsufficientStock too.
 */
final class PostingService
{
    public function __construct(
        private readonly Availability $availability,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * Quantities are entered as whole numbers in the chosen unit: "12", never
     * "1.5". The forms and the import check with this too, to say so before saving.
     */
    public static function whole(string $qty): bool
    {
        return preg_match('/^[+-]?\d+$/', trim($qty)) === 1;
    }

    /** Most a user may enter on one line, in the line's unit: catches extra zeros and pasted barcodes. */
    public const MAX_QTY = 999999;

    /**
     * Most a line may come to in the base unit (quantity × unit factor): far
     * below what stock_movement.qty_base (NUMERIC(18,4)) can hold.
     */
    public const MAX_BASE = 99999999999;

    /**
     * More than MAX_QTY in the line's unit, or (with $base) more than MAX_BASE
     * in the base unit. The forms and the import check with this too.
     */
    public static function tooLarge(string $qty, ?float $base = null): bool
    {
        return (float) trim($qty) > self::MAX_QTY || ($base !== null && $base > self::MAX_BASE);
    }

    /**
     * "At most 999,999 per line…", shown under the field like any line error.
     */
    public static function tooLargeError(string $key, string $field = 'qty'): PostingRejected
    {
        return new PostingRejected('transactions.form.errors.too_large', ['max' => Format::qty(self::MAX_QTY)], $key, $field);
    }

    /**
     * @throws StockException when the document can't be posted; nothing is saved
     */
    public function post(Document $document, User $by): StockTransaction
    {
        // The same form submitted again (double click, retry after a lost answer).
        if ($existing = $this->alreadyPosted($document, $by)) {
            return $existing;
        }

        $type = TransactionTypes::get($document->type);

        // Opening Balance / Receive / Delivery note post at once; a Transfer is dispatched
        // here and posted at the destination by receive(); Damage / Adjustment post
        // at once or wait for approve() when a line reaches its approval limit.
        if (! $type->postsDirectly() && ! $type->dispatchReceive() && ! $type->supportsApproval()) {
            throw new PostingRejected('stock.errors.type_not_supported');
        }

        if (! $by->canAccessWarehouse($document->warehouseId)) {
            throw new NotAllowed('stock.errors.warehouse_not_allowed');
        }

        if ($document->lines === []) {
            throw new PostingRejected('transactions.form.add_a_line');
        }

        try {
            return $this->translateDatabaseErrors(fn () => DB::transaction(fn () => $this->postDocument($type, $document, $by)));
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions of the same form at the same moment: the other one
            // saved it (stock_transaction.client_request_id is unique).
            return $this->alreadyPosted($document, $by) ?? throw $e;
        }
    }

    /**
     * The transaction this user already created from the same form submission, if any.
     */
    private function alreadyPosted(Document $document, User $by): ?StockTransaction
    {
        $requestId = $this->requestId($document);

        return $requestId === null ? null : StockTransaction::withoutGlobalScopes()
            ->where('client_request_id', $requestId)
            ->where('created_by', $by->id)
            ->first();
    }

    /**
     * The document's submission token, when it is a UUID (anything else is ignored).
     */
    private function requestId(Document $document): ?string
    {
        return Str::isUuid($document->requestId) ? strtolower((string) $document->requestId) : null;
    }

    /**
     * Reverses every movement of a posted Opening Balance / Receive / Delivery note and
     * marks it CANCELLED. A receipt can't be cancelled once part of its stock
     * has gone out (StockAlreadyUsed); a delivery note puts the stock back.
     *
     * @throws StockException
     */
    public function cancel(StockTransaction $transaction, User $by, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PostingRejected('stock.errors.cancel_reason_required', [], null, 'reason');
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new PostingRejected('settings.errors.too_long', ['max' => self::MAX_REASON], null, 'reason');
        }

        $this->translateDatabaseErrors(fn () => DB::transaction(function () use ($transaction, $by, $reason) {
            // Lock the header first: two cancels of the same document queue up here.
            $header = DB::table('stock_transaction')->where('id', $transaction->id)->lockForUpdate()->first();
            $type = TransactionTypes::get($header->transaction_type);

            if ($type->dispatchReceive()) {
                $this->cancelTransfer($header, $by, $reason);

                return;
            }

            if ($header->status !== 'POSTED' || ! $type->postsDirectly()) {
                throw new PostingRejected('stock.errors.cannot_cancel_status');
            }

            if (! $by->canAccessWarehouse($header->warehouse_id)) {
                throw new NotAllowed('stock.errors.warehouse_not_allowed');
            }

            $movements = DB::table('stock_movement')->where('transaction_id', $header->id)->orderBy('seq')->get();
            $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($movements->pluck('product_id')->unique())->keyBy('id');

            // Receipts: everything received must still be here, per item and expiry date.
            foreach ($movements->where('direction', 'IN')->groupBy(fn ($m) => $m->product_id.'|'.$m->expiry_date) as $group) {
                $first = $group->first();
                $balance = $this->lockBalance($first->warehouse_id, $first->customer_id, $first->product_id, $first->expiry_date);
                $received = (float) $group->sum('qty_base');

                if ($balance < $received) {
                    $product = $products[$first->product_id];
                    throw new StockAlreadyUsed('stock.errors.already_used', [
                        'item' => $product->name(),
                        'received' => $product->converter()->breakdown($received),
                        'left' => $product->converter()->breakdown($balance),
                    ]);
                }
            }

            $this->reverseSerials($header, $type);

            foreach ($movements as $movement) {
                $this->move($header->id, $movement->line_id, $movement->warehouse_id, $movement->customer_id, $movement->product_id, $movement->expiry_date,
                    $movement->direction === 'IN' ? 'OUT' : 'IN', (float) $movement->qty_base);
            }

            DB::table('stock_transaction')->where('id', $header->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);

            Audit::record('stock_transaction', $header->id, 'CANCEL',
                ['doc_no' => $header->doc_no, 'status' => 'POSTED'],
                ['doc_no' => $header->doc_no, 'status' => 'CANCELLED', 'reversed_movements' => $movements->count(), 'reason' => $reason],
                $by->id,
            );
        }));
    }

    /**
     * Why the document cannot be cancelled now (part of its stock has gone out,
     * it was already received...), or null when it can: cancel() run and rolled
     * back, so the answer follows the same rules. Lets the page explain instead
     * of asking to confirm something that would fail.
     */
    public function cancelRefusal(StockTransaction $transaction, User $by): ?StockException
    {
        DB::beginTransaction();

        try {
            $this->cancel($transaction, $by, 'check');

            return null;
        } catch (StockException $e) {
            return $e;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Confirms what arrived of a transfer that is In transit, at its destination:
     * IN movements there for the received quantities (with the same expiry
     * dates and serials as were sent), the in-transit stock cleared, and the
     * transfer POSTED. Less than sent is allowed with a reason (recorded on the
     * line); more is refused.
     *
     * Lines short of stock receive their nearest expiry dates first, so the
     * shortage falls on the stock that expires last.
     *
     * @param  array<string, ReceivedLine>  $received  keyed by stock_transaction_line.id
     *
     * @throws StockException with the line id as row and `received` / `reason` / `serials` as field
     */
    public function receive(StockTransaction $transaction, array $received, User $by): void
    {
        $this->translateDatabaseErrors(fn () => DB::transaction(function () use ($transaction, $received, $by) {
            // Lock the header first: a second "Confirm receipt" waits here, then finds it received.
            $header = DB::table('stock_transaction')->where('id', $transaction->id)->lockForUpdate()->first();
            $type = TransactionTypes::get($header->transaction_type);

            if (! $type->dispatchReceive()) {
                throw new PostingRejected('stock.errors.type_not_supported');
            }

            if ($header->status !== 'DISPATCHED') {
                throw $header->status === 'POSTED'
                    ? $this->alreadyProcessed('transfers.errors.already_received', $header->received_by, $header->received_at, $header->warehouse_id)
                    : new PostingRejected('transfers.errors.not_in_transit');
            }

            if (! $by->canAccessWarehouse($header->to_warehouse_id)) {
                throw new NotAllowed('transfers.errors.not_destination');
            }

            $lines = DB::table('stock_transaction_line as l')
                ->join('unit as u', 'u.id', '=', 'l.unit_id')
                ->where('l.transaction_id', $header->id)
                ->orderBy('l.line_no')
                ->get(['l.*', 'u.code as unit_code']);
            $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($lines->pluck('product_id')->unique())->keyBy('id');
            $plan = [];
            // The serials sent on every line, in one query (locked in id order).
            $serialsByLine = DB::table('stock_transaction_line_serial as ls')
                ->join('stock_transaction_line as l', 'l.id', '=', 'ls.line_id')
                ->join('serial_number as s', 's.id', '=', 'ls.serial_id')
                ->where('l.transaction_id', $header->id)
                ->orderBy('s.id')
                ->lockForUpdate()
                ->get(['ls.line_id', 's.id', 's.serial_no'])
                ->groupBy('line_id');

            // Check every line first; nothing is written until all are valid.
            foreach ($lines as $line) {
                $entry = $received[$line->id] ?? throw new PostingRejected('transfers.errors.received_required', [], $line->id, 'received');
                $product = $products[$line->product_id];
                $sentBase = (float) $line->qty_base;
                $sentSerials = $serialsByLine->get($line->id, collect())->pluck('serial_no', 'id')->all();

                $text = trim((string) $entry->qty);
                if (! self::whole($text)) {
                    throw new PostingRejected('transactions.form.errors.number', [], $line->id, 'received');
                }

                if ((float) $text < 0) {
                    throw new PostingRejected('transactions.form.errors.not_negative', [], $line->id, 'received');
                }

                if (self::tooLarge($text)) {
                    throw self::tooLargeError($line->id, 'received');
                }

                $receivedBase = $product->converter()->toBase($text, $line->unit_code);

                if ($receivedBase > $sentBase + 1e-9) {
                    throw new PostingRejected('transfers.errors.more_than_sent', ['qty' => $product->converter()->breakdown($sentBase, [$line->unit_code])], $line->id, 'received');
                }

                if (round($receivedBase, 4) != $receivedBase) {
                    throw new PostingRejected('stock.errors.not_exact', [], $line->id, 'received');
                }

                $arrivedSerials = [];
                if ($sentSerials !== []) {
                    $arrivedSerials = array_values(array_unique($entry->serials));
                    $unknown = array_diff($arrivedSerials, $sentSerials);

                    if ($unknown !== []) {
                        throw new SerialProblem('transfers.errors.serial_not_sent', ['serial' => reset($unknown)], (string) reset($unknown), $line->id);
                    }

                    if (count($arrivedSerials) !== (int) $receivedBase) {
                        throw new PostingRejected('stock.errors.serial_count', ['count' => Format::qty($receivedBase)], $line->id, 'serials');
                    }
                }

                $short = $receivedBase < $sentBase;
                $reason = $short ? $entry->reason : null;

                if ($short && ! in_array($reason, self::SHORTAGE_REASONS, true)) {
                    throw new PostingRejected('transfers.errors.reason_required', [], $line->id, 'reason');
                }

                $plan[] = compact('line', 'receivedBase', 'reason', 'sentSerials', 'arrivedSerials');
            }

            // The expiry dates each line left the source with, nearest first (all lines in one query).
            $sentByLine = DB::table('stock_movement')
                ->whereIn('line_id', $lines->pluck('id'))
                ->where('direction', 'OUT')
                ->orderByRaw('expiry_date ASC NULLS LAST, seq ASC')
                ->get(['line_id', 'expiry_date', 'qty_base'])
                ->groupBy('line_id');
            $arrivedIds = [];
            $lostIds = [];

            foreach ($plan as ['line' => $line, 'receivedBase' => $receivedBase, 'reason' => $reason, 'sentSerials' => $sentSerials, 'arrivedSerials' => $arrivedSerials]) {
                $sent = $sentByLine->get($line->id, collect());

                $remaining = $receivedBase;
                foreach ($sent as $movement) {
                    $qty = round(min((float) $movement->qty_base, $remaining), 4);

                    if ($qty > 0) {
                        $this->move($header->id, $line->id, $header->to_warehouse_id, $header->customer_id, $line->product_id, $movement->expiry_date, 'IN', $qty);
                        $remaining = round($remaining - $qty, 4);
                    }
                }

                foreach ($sentSerials as $serialId => $serialNo) {
                    if (in_array($serialNo, $arrivedSerials, true)) {
                        $arrivedIds[] = $serialId;
                    } else {
                        $lostIds[] = $serialId;
                    }
                }

                DB::table('stock_transaction_line')->where('id', $line->id)->update(['qty_received' => $receivedBase, 'shortage_reason' => $reason]);
            }

            if ($arrivedIds !== []) {
                DB::table('serial_number')->whereIn('id', $arrivedIds)->update(['warehouse_id' => $header->to_warehouse_id, 'status' => 'IN_STOCK', 'updated_at' => now()]);
            }

            // Serials that didn't arrive leave stock. serial_status has no "lost", so they are DAMAGED.
            if ($lostIds !== []) {
                DB::table('serial_number')->whereIn('id', $lostIds)->update(['warehouse_id' => null, 'status' => 'DAMAGED', 'updated_at' => now()]);
            }

            DB::table('in_transit_balance')->where('transaction_id', $header->id)->delete();
            DB::table('stock_transaction')->where('id', $header->id)->update([
                'status' => 'POSTED',
                'received_at' => now(),
                'received_by' => $by->id,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);

            Audit::record('stock_transaction', $header->id, 'RECEIVE',
                ['doc_no' => $header->doc_no, 'status' => 'DISPATCHED'],
                ['doc_no' => $header->doc_no, 'status' => 'POSTED', 'short_lines' => count(array_filter($plan, fn ($p) => $p['reason'] !== null))],
                $by->id,
            );
        }));
    }

    /** Why a transfer line arrived short (stock_transaction_line.shortage_reason). */
    public const SHORTAGE_REASONS = ['DAMAGED_IN_TRANSIT', 'MISSING', 'COUNTING_ERROR'];

    /**
     * A transfer can be cancelled by its sender while In transit: the stock goes
     * back into the source (same expiry dates and serials) and in-transit is cleared.
     * Once received it is final.
     */
    private function cancelTransfer(object $header, User $by, string $reason): void
    {
        if ($header->status !== 'DISPATCHED') {
            throw $header->status === 'POSTED'
                ? $this->alreadyProcessed('transfers.errors.received_cannot_cancel', $header->received_by, $header->received_at, $header->warehouse_id)
                : new PostingRejected('stock.errors.cannot_cancel_status');
        }

        if (! $by->canAccessWarehouse($header->warehouse_id)) {
            throw new NotAllowed('transfers.errors.only_sender_cancels');
        }

        $movements = DB::table('stock_movement')->where('transaction_id', $header->id)->where('direction', 'OUT')->orderBy('seq')->get();

        foreach ($movements as $movement) {
            $this->move($header->id, $movement->line_id, $movement->warehouse_id, $movement->customer_id, $movement->product_id, $movement->expiry_date, 'IN', (float) $movement->qty_base);
        }

        $serials = DB::table('stock_transaction_line_serial as ls')
            ->join('stock_transaction_line as l', 'l.id', '=', 'ls.line_id')
            ->where('l.transaction_id', $header->id)
            ->pluck('ls.serial_id');

        DB::table('serial_number')->whereIn('id', $serials)->update(['warehouse_id' => $header->warehouse_id, 'status' => 'IN_STOCK', 'updated_at' => now()]);
        DB::table('in_transit_balance')->where('transaction_id', $header->id)->delete();
        DB::table('stock_transaction')->where('id', $header->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);

        Audit::record('stock_transaction', $header->id, 'CANCEL',
            ['doc_no' => $header->doc_no, 'status' => 'DISPATCHED'],
            ['doc_no' => $header->doc_no, 'status' => 'CANCELLED', 'returned_movements' => $movements->count(), 'reason' => $reason],
            $by->id,
        );
    }

    private function postDocument(TypeUi $type, Document $document, User $by): StockTransaction
    {
        $warehouse = DB::table('warehouse')->where('id', $document->warehouseId)->where('active', true)->first()
            ?? throw new PostingRejected('stock.errors.warehouse_inactive');

        if (! DB::table('customer')->where('id', $document->customerId)->where('active', true)->exists()) {
            throw new PostingRejected('stock.errors.customer_inactive');
        }

        $today = $this->availability->today($warehouse->id);

        if ($document->date->toDateString() > $today->toDateString()) {
            throw new PostingRejected('transactions.form.errors.date_future');
        }

        $transfer = $type->dispatchReceive();

        if ($transfer && ($document->toWarehouseId === null
            || $document->toWarehouseId === $warehouse->id
            || ! DB::table('warehouse')->where('id', $document->toWarehouseId)->where('active', true)->exists())) {
            throw new PostingRejected('transactions.form.errors.to_warehouse_invalid');
        }

        /** @var Collection<string, Product> $products */
        $products = Product::withoutGlobalScopes()
            ->with(['baseUnit', 'units.unit'])
            ->where('customer_id', $document->customerId)
            ->where('active', true)
            ->findMany(array_map(fn (DocumentLine $line) => $line->productId, $document->lines))
            ->keyBy('id');

        $this->assertNoDuplicateLines($type, $document, $products);

        if ($type->supportsApproval()) {
            return $this->submit($type, $document, $by, $warehouse, $products);
        }

        $transactionId = (string) DB::table('stock_transaction')->insertGetId([
            'doc_no' => $this->numbers->next($type, CompanyTime::now()->year),
            'transaction_type' => $type->code,
            // A transfer stays In transit until the destination confirms receipt.
            'status' => $transfer ? 'DISPATCHED' : 'POSTED',
            'warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $transfer ? $document->toWarehouseId : null,
            'customer_id' => $document->customerId,
            'reference_note' => filled($document->note) ? trim($document->note) : null,
            'document_date' => $document->date->toDateString(),
            'created_by' => $by->id,
            'client_request_id' => $this->requestId($document),
            'draft_id' => $document->draftId,
            'customer_branch_id' => $document->branchId,
            'dispatched_at' => $transfer ? now() : null,
            'dispatched_by' => $transfer ? $by->id : null,
            'posted_at' => $transfer ? null : now(),
        ], 'id');

        $lineNo = 0;
        $seenSerials = [];

        foreach ($document->lines as $key => $line) {
            $product = $products[$line->productId] ?? throw new PostingRejected('transactions.form.errors.choose_item', [], $key, 'item');
            $converter = $product->converter();

            if (! $converter->has($line->unit)) {
                throw new PostingRejected('transactions.form.errors.choose_unit', [], $key, 'unit');
            }

            $qty = $this->quantity($line->qty, $key);
            $qtyBase = $converter->toBase($qty, $line->unit);

            if (self::tooLarge((string) $qty, $qtyBase)) {
                throw self::tooLargeError($key);
            }

            if (round($qtyBase, 4) != $qtyBase) {
                throw new PostingRejected('stock.errors.not_exact', [], $key, 'qty');
            }

            $serials = $product->track_serial ? $this->serials($line, $qtyBase, $seenSerials, $key) : [];

            if ($product->track_serial && $product->track_expiry) {
                throw new PostingRejected('stock.errors.serial_and_expiry', [], $key, 'item');
            }

            $context = [
                'transaction' => $transactionId,
                'line_no' => ++$lineNo,
                'warehouse' => $warehouse->id,
                'customer' => $document->customerId,
                'unit' => $product->units->firstWhere('unit.code', $line->unit)->unit_id,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                // Stock going out on a document dated back is what was here on that date.
                'date' => $document->date,
            ];

            $type->postingDirection() === 'IN'
                ? $this->postIn($context, $product, $line, $serials, $key)
                : $this->postOut($context, $product, $product->track_expiry ? $this->chosenExpiry($line, $key) : null, $serials, $type->usesExpired(), $key, inTransit: $transfer);
        }

        $transaction = StockTransaction::withoutGlobalScopes()->findOrFail($transactionId);

        Audit::record('stock_transaction', $transactionId, $transfer ? 'DISPATCH' : 'POST', null, [
            'doc_no' => $transaction->doc_no,
            'type' => $type->code,
            'status' => $transaction->status,
            'lines' => $lineNo,
        ], $by->id);

        return $transaction;
    }

    /**
     * No two lines with the same key (CLAUDE.md rule 12, TypeUi::lineKey()):
     * the later one is refused, naming the earlier line's number.
     *
     * @param  Collection<string, Product>  $products
     *
     * @throws DuplicateLine
     */
    private function assertNoDuplicateLines(TypeUi $type, Document $document, Collection $products): void
    {
        $seen = [];
        $number = 0;

        foreach ($document->lines as $key => $line) {
            $number++;
            $product = $products[$line->productId] ?? null;

            if ($product === null) {
                continue; // refused later, under its own row
            }

            $expiry = filled($line->expiry) ? trim((string) $line->expiry) : null;
            $lineKey = $type->lineKey($product->id, $line->unit, $expiry, $product->track_expiry);

            if (isset($seen[$lineKey])) {
                [$message, $params] = $type->duplicateMessage($product->name(), $line->unit, $expiry, $product->track_expiry, $seen[$lineKey]['number']);

                throw new DuplicateLine($message, $params, (string) $key, $seen[$lineKey]['key']);
            }

            $seen[$lineKey] = ['key' => (string) $key, 'number' => $number];
        }
    }

    /**
     * Posts a Damage / Adjustment that is waiting for approval: the same
     * movements it would have had if saved under the limit, checked against the
     * stock as it is now. An adjustment whose balance changed since it was
     * counted is refused (StaleCount): it must be rejected and counted again.
     *
     * @throws StockException
     */
    public function approve(StockTransaction $transaction, User $by): void
    {
        $this->translateDatabaseErrors(fn () => DB::transaction(function () use ($transaction, $by) {
            $header = $this->lockPending($transaction, $by);

            $this->postSubmittedLines($header, TransactionTypes::get($header->transaction_type));

            DB::table('approval')->where('transaction_id', $header->id)->whereNull('decision')->update([
                'decided_by' => $by->id,
                'decision' => 'APPROVED',
                'decided_at' => now(),
            ]);
            DB::table('stock_transaction')->where('id', $header->id)->update(['status' => 'POSTED', 'posted_at' => now(), 'updated_at' => now()]);

            Audit::record('stock_transaction', $header->id, 'APPROVE',
                ['doc_no' => $header->doc_no, 'status' => 'PENDING_APPROVAL'],
                ['doc_no' => $header->doc_no, 'status' => 'POSTED'],
                $by->id,
            );
        }));
    }

    /**
     * Rejects a document waiting for approval, with the reason the approver
     * gives. Stock is never touched.
     *
     * @throws StockException with field `reason` when the reason is missing
     */
    public function reject(StockTransaction $transaction, string $reason, User $by): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PostingRejected('stock.errors.reject_reason_required', [], null, 'reason');
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw new PostingRejected('settings.errors.too_long', ['max' => self::MAX_REASON], null, 'reason');
        }

        DB::transaction(function () use ($transaction, $reason, $by) {
            $header = $this->lockPending($transaction, $by);

            DB::table('approval')->where('transaction_id', $header->id)->whereNull('decision')->update([
                'decided_by' => $by->id,
                'decision' => 'REJECTED',
                'reason' => $reason,
                'decided_at' => now(),
            ]);
            DB::table('stock_transaction')->where('id', $header->id)->update(['status' => 'REJECTED', 'updated_at' => now()]);

            Audit::record('stock_transaction', $header->id, 'REJECT',
                ['doc_no' => $header->doc_no, 'status' => 'PENDING_APPROVAL'],
                ['doc_no' => $header->doc_no, 'status' => 'REJECTED', 'reason' => $reason],
                $by->id,
            );
        });
    }

    /**
     * Confirms a draft receipt / order: its lines become one posted document of
     * the draft's type in the draft's warehouse, linked to the draft and its
     * branch, dated today in that warehouse. All lines or none: when one line
     * can't be posted (not enough stock, a serial gone...), nothing is.
     *
     * Anyone with the type's confirm permission who works in the draft's
     * warehouse may confirm it, its creator included.
     *
     * @return list<StockTransaction> the document created (always one)
     *
     * @throws StockException with the draft line id as row
     */
    public function confirmDraft(StockDraft $draft, User $by): array
    {
        return $this->translateDatabaseErrors(fn () => DB::transaction(function () use ($draft, $by) {
            // Lock the draft first: a second "Confirm", or an edit, waits here and then finds it confirmed.
            $header = DB::table('stock_draft')->where('id', $draft->id)->lockForUpdate()->first();
            $kind = DraftTypes::get($header->transaction_type);

            if ($header->status !== 'DRAFT') {
                throw $header->status === 'POSTED'
                    ? $this->alreadyProcessed('drafts.errors.already_confirmed', $header->confirmed_by, $header->confirmed_at, null)
                    : new AlreadyProcessed('drafts.errors.already_cancelled');
            }

            if (! $by->hasPermission($kind->confirmPermission()) || ! $by->canUseBranch($header->customer_branch_id, $header->customer_id)) {
                throw new NotAllowed('drafts.errors.cannot_confirm');
            }

            $lines = DB::table('stock_draft_line as l')
                ->join('unit as u', 'u.id', '=', 'l.unit_id')
                ->where('l.draft_id', $header->id)
                ->orderBy('l.line_no')
                ->get(['l.*', 'u.code as unit_code']);

            if ($lines->isEmpty()) {
                throw new PostingRejected('transactions.form.add_a_line');
            }

            $warehouseId = (string) $header->warehouse_id;

            if (! $by->canAccessWarehouse($warehouseId)) {
                throw new NotAllowed('drafts.errors.warehouse_not_allowed', [
                    'warehouse' => (string) DB::table('warehouse')->where('id', $warehouseId)->value('name'),
                ]);
            }

            $documentLines = [];

            foreach ($lines as $line) {
                $documentLines[(string) $line->id] = new DocumentLine(
                    productId: $line->product_id,
                    unit: $line->unit_code,
                    qty: (string) (int) round((float) $line->qty_entered),
                    expiry: $line->expiry_date,
                    serials: json_decode((string) $line->serials, true) ?: [],
                );
            }

            $created = [$this->postDocument($kind->type, new Document(
                type: $kind->type->code,
                warehouseId: $warehouseId,
                customerId: $header->customer_id,
                date: $this->availability->today($warehouseId),
                note: $header->reference_note,
                lines: $documentLines,
                draftId: $header->id,
                branchId: $header->customer_branch_id,
            ), $by)];

            DB::table('stock_draft')->where('id', $header->id)->update([
                'status' => 'POSTED',
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

            Audit::record('stock_draft', $header->id, 'CONFIRM',
                ['doc_no' => $header->doc_no, 'status' => 'DRAFT'],
                ['doc_no' => $header->doc_no, 'status' => 'POSTED', 'documents' => array_map(fn (StockTransaction $t) => $t->doc_no, $created)],
                $by->id,
            );

            return $created;
        }));
    }

    /**
     * "Already received by Nour at 10:42": who got there first and when, in the
     * company's time zone (Format::dateTime()).
     */
    private function alreadyProcessed(string $key, ?string $userId, ?string $at, ?string $warehouseId): AlreadyProcessed
    {
        return new AlreadyProcessed($key, [
            'name' => (string) DB::table('app_user')->where('id', $userId)->value('full_name'),
            'time' => $at === null ? '' : Format::dateTime(CarbonImmutable::parse($at)),
        ]);
    }

    /** Longest rejection reason, in characters. */
    public const MAX_REASON = 500;

    /**
     * Locks a document waiting for approval that $by may decide on: not their
     * own (also enforced by approval.chk_approver_not_requester), in one of
     * their warehouses. A second decision finds it decided.
     */
    private function lockPending(StockTransaction $transaction, User $by): object
    {
        $header = DB::table('stock_transaction')->where('id', $transaction->id)->lockForUpdate()->first();

        if ($header->status !== 'PENDING_APPROVAL') {
            $decided = DB::table('approval')->where('transaction_id', $header->id)->whereNotNull('decision')->orderByDesc('decided_at')->first();

            throw $decided === null
                ? new AlreadyProcessed('stock.errors.not_pending')
                : $this->alreadyProcessed(
                    $decided->decision === 'APPROVED' ? 'stock.errors.already_approved' : 'stock.errors.already_rejected',
                    $decided->decided_by, $decided->decided_at, $header->warehouse_id,
                );
        }

        if ($header->created_by === $by->id) {
            throw new SelfApproval('stock.errors.own_document');
        }

        if (! $by->canAccessWarehouse($header->warehouse_id)) {
            throw new NotAllowed('stock.errors.warehouse_not_allowed');
        }

        return $header;
    }

    /**
     * Damage / Adjustment: checks and saves the lines, then posts them at once,
     * or, when any line's base quantity reaches its approval limit
     * (fn_resolve_threshold), keeps the document PENDING_APPROVAL with an
     * approval request and no movements.
     *
     * Adjustment lines with no difference are not saved; when no line differs
     * there is nothing to save.
     *
     * @param  Collection<string, Product>  $products
     */
    private function submit(TypeUi $type, Document $document, User $by, object $warehouse, Collection $products): StockTransaction
    {
        if (! in_array($document->reason, $type->reasons(), true)) {
            throw new PostingRejected('transactions.form.errors.reason_required', [], null, 'reason');
        }

        $lines = [];
        $seen = [];

        foreach ($document->lines as $key => $line) {
            $product = $products[$line->productId] ?? throw new PostingRejected('transactions.form.errors.choose_item', [], $key, 'item');

            if (! $product->converter()->has($line->unit)) {
                throw new PostingRejected('transactions.form.errors.choose_unit', [], $key, 'unit');
            }

            $prepared = $type->countsStock()
                ? $this->prepareCount($product, $line, $warehouse->id, $document->customerId, $key, $seen)
                : $this->prepareRemoval($type, $product, $line, $warehouse->id, $document->customerId, $document->date, $key, $seen);

            if ($prepared !== null) {
                $lines[$key] = $prepared;
            }
        }

        if ($lines === []) {
            throw new PostingRejected($type->countsStock() ? 'stock.errors.nothing_to_adjust' : 'transactions.form.add_a_line');
        }

        $thresholds = app(ApprovalThresholds::class);
        $overLimit = collect($lines)->contains(fn (array $line) => $thresholds->needsApproval(
            $line['qty_base'],
            $thresholds->resolve($line['product']->id, $document->customerId, $type->code),
        ));
        // Someone who may approve this type needs nobody else's approval: their
        // own document over the limit is posted at once (and recorded as such).
        $selfApproved = $overLimit && $type->approvePermission() !== null && $by->hasPermission($type->approvePermission());
        $pending = $overLimit && ! $selfApproved;

        $transactionId = (string) DB::table('stock_transaction')->insertGetId([
            'doc_no' => $this->numbers->next($type, CompanyTime::now()->year),
            'transaction_type' => $type->code,
            'status' => $pending ? 'PENDING_APPROVAL' : 'POSTED',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $document->customerId,
            'reason_code' => $document->reason,
            'reference_note' => filled($document->note) ? trim($document->note) : null,
            'document_date' => $document->date->toDateString(),
            'created_by' => $by->id,
            'client_request_id' => $this->requestId($document),
            'posted_at' => $pending ? null : now(),
        ], 'id');

        $lineNo = 0;
        $lineIds = [];

        foreach ($lines as $key => $line) {
            $lineId = (string) DB::table('stock_transaction_line')->insertGetId([
                'transaction_id' => $transactionId,
                'line_no' => ++$lineNo,
                'product_id' => $line['product']->id,
                'expiry_date' => $line['expiry'],
                'unit_id' => $line['product']->units->firstWhere('unit.code', $line['unit'])->unit_id,
                'qty_entered' => $line['qty_entered'],
                'qty_base' => $line['qty_base'],
                'qty_system' => $line['qty_system'],
                'qty_counted' => $line['qty_counted'],
                'direction' => $line['direction'],
            ], 'id');

            $this->linkSerials($lineId, $line['serial_ids']);

            $lineIds[$key] = $lineId;
        }

        $header = DB::table('stock_transaction')->where('id', $transactionId)->first();

        if ($pending) {
            DB::table('approval')->insert(['transaction_id' => $transactionId, 'requested_by' => $by->id]);
        } else {
            $this->postSubmittedLines($header, $type, $lineIds);
        }

        Audit::record('stock_transaction', $transactionId, $pending ? 'SUBMIT' : 'POST', null, [
            'doc_no' => $header->doc_no,
            'type' => $type->code,
            'status' => $header->status,
            'lines' => $lineNo,
        ] + ($selfApproved ? ['self_approved' => true] : []), $by->id);

        return StockTransaction::withoutGlobalScopes()->findOrFail($transactionId);
    }

    /**
     * A Damage line: like a Delivery note line (a quantity, or the serials picked), and
     * there must be enough in stock now, expired stock included when the type
     * uses them. An expiry date chosen on the line is kept on it (and must have
     * enough on its own); otherwise dates are chosen (FEFO) when it is posted.
     * Dated before today, only what was here on that date counts.
     *
     * @param  array<string, mixed>  $seen  quantities and serials taken by earlier lines
     * @return array<string, mixed>
     */
    private function prepareRemoval(TypeUi $type, Product $product, DocumentLine $line, string $warehouseId, string $customerId, CarbonImmutable $date, string $key, array &$seen): array
    {
        $qty = $this->quantity($line->qty, $key);
        $qtyBase = $product->converter()->toBase($qty, $line->unit);

        if (self::tooLarge((string) $qty, $qtyBase)) {
            throw self::tooLargeError($key);
        }

        if (round($qtyBase, 4) != $qtyBase) {
            throw new PostingRejected('stock.errors.not_exact', [], $key, 'qty');
        }

        if ($product->track_serial && $product->track_expiry) {
            throw new PostingRejected('stock.errors.serial_and_expiry', [], $key, 'item');
        }

        $serialIds = [];

        if ($product->track_serial) {
            $seenSerials = $seen['serials'] ?? [];
            $picked = $this->serials($line, $qtyBase, $seenSerials, $key);

            $rows = $picked === [] ? collect() : DB::table('serial_number')->where('product_id', $product->id)->whereIn('serial_no', $picked)->get()->keyBy('serial_no');

            foreach ($picked as $serial) {
                $row = $rows->get($serial);

                if ($row === null || $row->status !== 'IN_STOCK' || $row->warehouse_id !== $warehouseId) {
                    throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $serial], $serial, $key);
                }

                $serialIds[] = $row->id;
            }

            $this->checkSerialsOnDate($warehouseId, $product->id, $picked, $date, $key);
            $seen['serials'] = $seenSerials;
        }

        // Enough for this line (of the chosen expiry date, if any) after the
        // earlier lines of the same item. Checked again, with locks, when the
        // stock actually goes out.
        $expiry = $product->track_expiry ? $this->chosenExpiry($line, $key) : null;
        $seenKey = 'qty|'.$product->id;

        $lot = $expiry === null ? null : (collect($this->availability->rows($warehouseId, $customerId, $product->id))->firstWhere('expiry', $expiry)
            ?? throw new PostingRejected('transactions.form.errors.expiry_not_here', [], $key, 'expiry'));

        if ($lot !== null) {
            if ($lot['expired'] && ! $type->usesExpired()) {
                throw new PostingRejected('stock.errors.expiry_expired', ['date' => Format::date(CarbonImmutable::parse($expiry))], $key, 'expiry');
            }

            $seenKey .= '|'.$expiry;
        }

        $taken = $seen[$seenKey] ?? 0.0;

        // Now, and (dated back) on the document's date.
        foreach ($this->availability->isBackdated($warehouseId, $date) ? [null, $date] : [null] as $on) {
            $available = $lot !== null
                ? (float) (collect($this->availability->rows($warehouseId, $customerId, $product->id, $on))->firstWhere('expiry', $expiry)['qty'] ?? 0)
                : $this->availability->quantity($warehouseId, $customerId, $product->id, excludeExpired: ! $type->usesExpired(), date: $on);

            if ($available - $taken < $qtyBase - 1e-9) {
                throw Availability::notEnough($product, $warehouseId, max($available - $taken, 0), $qtyBase, row: $key, date: $on);
            }
        }

        $seen[$seenKey] = $taken + $qtyBase;

        return [
            'product' => $product,
            'unit' => $line->unit,
            'qty_entered' => $qty,
            'qty_base' => $qtyBase,
            'expiry' => $expiry,
            'qty_system' => null,
            'qty_counted' => null,
            'direction' => null,
            'serial_ids' => $serialIds,
        ];
    }

    /**
     * An Adjustment line: what was counted (0 allowed) against the balance now,
     * per item, or per expiry date for expiry items. Serial items are counted by the
     * serial numbers found; those not found leave stock. Returns null when the
     * count matches (such lines are not saved).
     *
     * The line keeps the unit the user counted in when the system quantity, the
     * count and the difference are all whole in it; otherwise the base unit.
     *
     * @param  array<string, mixed>  $seen  item (and expiry date) already counted on an earlier line
     * @return array<string, mixed>|null
     */
    private function prepareCount(Product $product, DocumentLine $line, string $warehouseId, string $customerId, string $key, array &$seen): ?array
    {
        $converter = $product->converter();
        $expiry = null;

        if ($product->track_expiry) {
            $expiry = $this->chosenExpiry($line, $key)
                ?? throw new PostingRejected('transactions.form.errors.expiry_choose', [], $key, 'expiry');
        }

        if (isset($seen['count|'.$product->id.'|'.$expiry])) {
            throw new PostingRejected('transactions.form.errors.counted_twice', [], $key, 'item');
        }

        $seen['count|'.$product->id.'|'.$expiry] = true;
        $this->lockItem($product->id);
        $system = $this->lockBalance($warehouseId, $customerId, $product->id, $expiry);
        $missing = [];

        if ($product->track_serial) {
            $inStock = DB::table('serial_number')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'IN_STOCK')
                ->lockForUpdate()
                ->pluck('id', 'serial_no')
                ->all();
            $found = array_values(array_unique(array_filter(array_map('trim', $line->serials), fn (string $serial) => $serial !== '')));

            foreach ($found as $serial) {
                if (! isset($inStock[$serial])) {
                    throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $serial], $serial, $key);
                }
            }

            $missing = array_values(array_diff_key($inStock, array_flip($found)));
            $counted = (float) count($found);
        } else {
            $text = trim((string) $line->qty);

            if (! self::whole($text)) {
                throw new PostingRejected('transactions.form.errors.number', [], $key, 'qty');
            }

            if ((float) $text < 0) {
                throw new PostingRejected('transactions.form.errors.not_negative', [], $key, 'qty');
            }

            $counted = $converter->toBase($text, $line->unit);

            if (self::tooLarge($text, $counted)) {
                throw self::tooLargeError($key);
            }

            if (round($counted, 4) != $counted) {
                throw new PostingRejected('stock.errors.not_exact', [], $key, 'qty');
            }
        }

        $difference = round($counted - $system, 4);

        if ($difference == 0) {
            return null;
        }

        $factor = $converter->factor($line->unit);
        $whole = fn (float $qty) => abs(round($qty / $factor, 4) * $factor - $qty) < 1e-9;
        $unit = $whole($system) && $whole($counted) && $whole(abs($difference)) ? $line->unit : $converter->base();

        return [
            'product' => $product,
            'unit' => $unit,
            'qty_entered' => round(abs($difference) / $converter->factor($unit), 4),
            'qty_base' => abs($difference),
            'expiry' => $expiry,
            'qty_system' => $system,
            'qty_counted' => $counted,
            'direction' => $difference > 0 ? 'IN' : 'OUT',
            'serial_ids' => $missing,
        ];
    }

    /**
     * Writes the movements of a saved Damage / Adjustment, against the stock as
     * it is now (balances locked FOR UPDATE).
     *
     * Damage lines go out like a Delivery note: expiry items from the expiry date saved
     * on the line, or else nearest expiry first (expired stock first when the
     * type uses it), serial items by
     * the serials on the line. Adjustment lines move their difference in their
     * direction; a balance (or serial) that is no longer what was counted
     * (an approval that came late) is refused with StaleCount. Serials that leave stock become
     * DAMAGED (serial_status has no "lost").
     *
     * @param  array<string, string>  $lineIds  form row key => line id, to show problems on the row
     */
    private function postSubmittedLines(object $header, TypeUi $type, array $lineIds = []): void
    {
        $lines = DB::table('stock_transaction_line')->where('transaction_id', $header->id)->orderBy('line_no')->get();
        $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($lines->pluck('product_id')->unique())->keyBy('id');
        // Damage dated back: only what was here on that date can go out.
        $date = CarbonImmutable::parse($header->document_date);
        // The serials of all lines, in one query (locked in id order).
        $serialsByLine = DB::table('stock_transaction_line_serial as ls')
            ->join('stock_transaction_line as l', 'l.id', '=', 'ls.line_id')
            ->join('serial_number as s', 's.id', '=', 'ls.serial_id')
            ->where('l.transaction_id', $header->id)
            ->orderBy('s.id')
            ->lockForUpdate()
            ->get(['ls.line_id', 's.id', 's.serial_no', 's.status', 's.warehouse_id'])
            ->groupBy('line_id');

        foreach ($lines as $line) {
            $product = $products[$line->product_id];
            $key = array_search($line->id, $lineIds, true) ?: null;
            $serials = $serialsByLine->get($line->id, collect());
            $gone = $serials->first(fn ($serial) => $serial->status !== 'IN_STOCK' || $serial->warehouse_id !== $header->warehouse_id);

            if ($line->direction !== null) {
                $this->lockItem($product->id);
                $current = $this->lockBalance($header->warehouse_id, $header->customer_id, $product->id, $line->expiry_date);

                if (abs($current - (float) $line->qty_system) > 1e-9 || $gone !== null) {
                    throw new StaleCount('stock.errors.count_changed', ['item' => $product->name()], $key);
                }

                $this->move($header->id, $line->id, $header->warehouse_id, $header->customer_id, $product->id, $line->expiry_date, $line->direction, (float) $line->qty_base);
            } elseif ($product->track_expiry && $line->expiry_date !== null) {
                // The expiry date the user chose when saving.
                $this->availability->takeExpiry($product, $header->warehouse_id, $header->customer_id, $line->expiry_date, (float) $line->qty_base, $type->usesExpired(), $key, $date);
                $this->move($header->id, $line->id, $header->warehouse_id, $header->customer_id, $product->id, $line->expiry_date, 'OUT', (float) $line->qty_base);
            } elseif ($product->track_expiry) {
                $allocations = $this->availability->allocate($product, $header->warehouse_id, $header->customer_id, (float) $line->qty_base, $type->usesExpired(), $key, $date);

                if (count($allocations) === 1) {
                    DB::table('stock_transaction_line')->where('id', $line->id)->update(['expiry_date' => $allocations[0]['expiry']]);
                }

                foreach ($allocations as $allocation) {
                    $this->move($header->id, $line->id, $header->warehouse_id, $header->customer_id, $product->id, $allocation['expiry'], 'OUT', $allocation['qty']);
                }
            } else {
                $available = $this->availability->lockNoExpiry($header->warehouse_id, $header->customer_id, $product->id);

                if ($available < (float) $line->qty_base - 1e-9) {
                    throw Availability::notEnough($product, $header->warehouse_id, $available, (float) $line->qty_base, row: $key);
                }

                $then = $this->availability->usable($available, $header->warehouse_id, $header->customer_id, $product->id, null, $date);

                if ($then < (float) $line->qty_base - 1e-9) {
                    throw Availability::notEnough($product, $header->warehouse_id, $then, (float) $line->qty_base, row: $key, date: $date);
                }

                if ($gone !== null) {
                    throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $gone->serial_no], $gone->serial_no, $key);
                }

                $this->checkSerialsOnDate($header->warehouse_id, $product->id, $serials->pluck('serial_no')->all(), $date, $key);

                $this->move($header->id, $line->id, $header->warehouse_id, $header->customer_id, $product->id, null, 'OUT', (float) $line->qty_base);
            }

            if ($serials->isNotEmpty()) {
                DB::table('serial_number')->whereIn('id', $serials->pluck('id'))->update(['warehouse_id' => null, 'status' => 'DAMAGED', 'updated_at' => now()]);
            }
        }
    }

    /**
     * Counts of the same item queue up here, even when it has no balance row
     * yet (nothing else to lock).
     */
    private function lockItem(string $productId): void
    {
        DB::table('product')->where('id', $productId)->lockForUpdate()->value('id');
    }

    /**
     * Locks one balance row (item, and expiry date or none) and returns it; 0 when there is none.
     */
    private function lockBalance(string $warehouseId, string $customerId, string $productId, ?string $expiry): float
    {
        return (float) (DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('expiry_key', $expiry ?? self::NO_EXPIRY)
            ->lockForUpdate()
            ->value('qty_base') ?? 0);
    }

    /** stock_balance.expiry_key / in_transit_balance.expiry_key of items without expiry. */
    private const NO_EXPIRY = '0001-01-01';

    /**
     * Receive / Opening Balance: adds stock. Expiry items need the expiry date
     * (their stock is kept per date); serial items register each serial here.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $serials
     */
    private function postIn(array $context, Product $product, DocumentLine $line, array $serials, string $key): void
    {
        $expiry = null;

        if ($product->track_expiry) {
            $expiry = $this->chosenExpiry($line, $key)
                ?? throw new PostingRejected('transactions.form.errors.expiry_required', [], $key, 'expiry');
        }

        $lineId = $this->insertLine($context, $product, $expiry);

        $this->move($context['transaction'], $lineId, $context['warehouse'], $context['customer'], $product->id, $expiry, 'IN', $context['qty_base']);

        if ($serials === []) {
            return;
        }

        $known = $this->lockSerials($product->id, $serials);

        foreach ($serials as $serial) {
            if (isset($known[$serial]) && in_array($known[$serial]->status, ['IN_STOCK', 'IN_TRANSIT'], true)) {
                throw new SerialProblem('stock.errors.serial_in_stock', ['serial' => $serial], $serial, $key);
            }
        }

        // Left before (issued / damaged) and come back in.
        if ($known !== []) {
            DB::table('serial_number')->whereIn('id', array_column($known, 'id'))->update(['warehouse_id' => $context['warehouse'], 'status' => 'IN_STOCK', 'updated_at' => now()]);
        }

        $new = array_values(array_filter($serials, fn (string $serial) => ! isset($known[$serial])));

        if ($new !== []) {
            DB::table('serial_number')->insert(array_map(fn (string $serial) => ['product_id' => $product->id, 'serial_no' => $serial, 'warehouse_id' => $context['warehouse'], 'status' => 'IN_STOCK'], $new));
        }

        $ids = DB::table('serial_number')->where('product_id', $product->id)->whereIn('serial_no', $serials)->pluck('id', 'serial_no');
        $this->linkSerials($lineId, array_map(fn (string $serial) => (string) $ids[$serial], $serials));
    }

    /**
     * Locks the existing rows of these serials of the item (in id order, so two
     * postings never lock them in opposite orders), by serial number.
     *
     * @param  list<string>  $serials
     * @return array<string, object{id: string, serial_no: string, status: string, warehouse_id: ?string}>
     */
    private function lockSerials(string $productId, array $serials): array
    {
        return DB::table('serial_number')
            ->where('product_id', $productId)
            ->whereIn('serial_no', $serials)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'serial_no', 'status', 'warehouse_id'])
            ->keyBy('serial_no')
            ->all();
    }

    /**
     * @param  list<string>  $serialIds
     */
    private function linkSerials(string $lineId, array $serialIds): void
    {
        if ($serialIds !== []) {
            DB::table('stock_transaction_line_serial')->insert(array_map(fn (string $id) => ['line_id' => $lineId, 'serial_id' => $id], $serialIds));
        }
    }

    /**
     * Delivery note / transfer dispatch: takes stock out. Expiry items are taken from
     * the expiry date chosen on the line or, when none is given, nearest expiry
     * first (FEFO), possibly from several dates (one movement per date); serial
     * items take exactly the serials picked, which must be in stock here. For a
     * transfer ($inTransit) the stock is recorded in in_transit_balance and its
     * serials are In transit, not issued. Dated back, only what was here on
     * the document's date can go out.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $serials
     */
    private function postOut(array $context, Product $product, ?string $expiry, array $serials, bool $useExpired, string $key, bool $inTransit = false): void
    {
        $needed = $context['qty_base'];
        $date = $context['date'];

        if ($product->track_expiry) {
            if ($expiry !== null) {
                $this->availability->takeExpiry($product, $context['warehouse'], $context['customer'], $expiry, $needed, $useExpired, $key, $date);
                $allocations = [['expiry' => $expiry, 'qty' => $needed]];
            } else {
                $allocations = $this->availability->allocate($product, $context['warehouse'], $context['customer'], $needed, $useExpired, $key, $date);
            }

            $lineId = $this->insertLine($context, $product, count($allocations) === 1 ? $allocations[0]['expiry'] : null);

            foreach ($allocations as $allocation) {
                $this->move($context['transaction'], $lineId, $context['warehouse'], $context['customer'], $product->id, $allocation['expiry'], 'OUT', $allocation['qty']);

                if ($inTransit) {
                    $this->addInTransit($context['transaction'], $product->id, $allocation['expiry'], $allocation['qty']);
                }
            }

            return;
        }

        $available = $this->availability->lockNoExpiry($context['warehouse'], $context['customer'], $product->id);

        if ($available < $needed) {
            throw Availability::notEnough($product, $context['warehouse'], $available, $needed, row: $key);
        }

        $then = $this->availability->usable($available, $context['warehouse'], $context['customer'], $product->id, null, $date);

        if ($then < $needed - 1e-9) {
            throw Availability::notEnough($product, $context['warehouse'], $then, $needed, row: $key, date: $date);
        }

        $serialIds = [];
        $known = $serials === [] ? [] : $this->lockSerials($product->id, $serials);

        foreach ($serials as $serial) {
            $row = $known[$serial] ?? null;

            if ($row === null || $row->status !== 'IN_STOCK' || $row->warehouse_id !== $context['warehouse']) {
                throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $serial], $serial, $key);
            }

            $serialIds[] = $row->id;
        }

        $this->checkSerialsOnDate($context['warehouse'], $product->id, $serials, $date, $key);

        $lineId = $this->insertLine($context, $product, null);
        $this->move($context['transaction'], $lineId, $context['warehouse'], $context['customer'], $product->id, null, 'OUT', $needed);

        if ($inTransit) {
            $this->addInTransit($context['transaction'], $product->id, null, $needed);
        }

        if ($serialIds !== []) {
            DB::table('serial_number')->whereIn('id', $serialIds)->update(['warehouse_id' => null, 'status' => $inTransit ? 'IN_TRANSIT' : 'ISSUED', 'updated_at' => now()]);
            $this->linkSerials($lineId, $serialIds);
        }
    }

    /**
     * Serials going out on a document dated back must have been here on that
     * date: one that arrived (again) later is refused.
     *
     * @param  list<string>  $serials
     */
    private function checkSerialsOnDate(string $warehouseId, string $productId, array $serials, CarbonImmutable $date, ?string $key): void
    {
        $late = array_values(array_diff($serials, $this->availability->serialsOnDate($warehouseId, $productId, $serials, $date)));

        if ($late !== []) {
            throw new SerialProblem('stock.errors.serial_not_on_date', ['serial' => $late[0], 'date' => Format::date($date)], $late[0], $key);
        }
    }

    /**
     * Records stock on its way (per transfer, item and expiry date).
     */
    private function addInTransit(string $transactionId, string $productId, ?string $expiry, float $qty): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO in_transit_balance (transaction_id, product_id, expiry_date, qty_base) VALUES (?, ?, ?, ?)
            ON CONFLICT (transaction_id, product_id, expiry_key)
            DO UPDATE SET qty_base = in_transit_balance.qty_base + EXCLUDED.qty_base
        SQL, [$transactionId, $productId, $expiry, $qty]);
    }

    /**
     * Cancelling: received serials leave stock again; issued serials come back.
     */
    private function reverseSerials(object $header, TypeUi $type): void
    {
        $serials = DB::table('stock_transaction_line_serial as ls')
            ->join('stock_transaction_line as l', 'l.id', '=', 'ls.line_id')
            ->join('serial_number as s', 's.id', '=', 'ls.serial_id')
            ->where('l.transaction_id', $header->id)
            ->lockForUpdate()
            ->get(['s.id', 's.serial_no', 's.status', 's.warehouse_id']);

        $received = $type->postingDirection() === 'IN';

        foreach ($serials as $serial) {
            if ($received && ($serial->status !== 'IN_STOCK' || $serial->warehouse_id !== $header->warehouse_id)) {
                throw new StockAlreadyUsed('stock.errors.serial_already_used', ['serial' => $serial->serial_no]);
            }

            if (! $received && $serial->status !== 'ISSUED') {
                throw new StockAlreadyUsed('stock.errors.serial_back_in_stock', ['serial' => $serial->serial_no]);
            }
        }

        if ($serials->isNotEmpty()) {
            DB::table('serial_number')->whereIn('id', $serials->pluck('id'))->update($received
                ? ['warehouse_id' => null, 'status' => 'ISSUED', 'updated_at' => now()]
                : ['warehouse_id' => $header->warehouse_id, 'status' => 'IN_STOCK', 'updated_at' => now()]);
        }
    }

    /**
     * The line's expiry date as Y-m-d, or null when none is given. A date that
     * isn't a real Y-m-d date is refused.
     */
    private function chosenExpiry(DocumentLine $line, string $key): ?string
    {
        $expiry = trim((string) $line->expiry);

        if ($expiry === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $expiry);

        if (! $date || $date->toDateString() !== $expiry) {
            throw new PostingRejected('transactions.form.errors.expiry_required', [], $key, 'expiry');
        }

        return $expiry;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function insertLine(array $context, Product $product, ?string $expiry): string
    {
        return (string) DB::table('stock_transaction_line')->insertGetId([
            'transaction_id' => $context['transaction'],
            'line_no' => $context['line_no'],
            'product_id' => $product->id,
            'expiry_date' => $expiry,
            'unit_id' => $context['unit'],
            'qty_entered' => $context['qty'],
            'qty_base' => $context['qty_base'],
        ], 'id');
    }

    /**
     * One ledger movement + balance update, through the database function.
     */
    private function move(string $transactionId, string $lineId, string $warehouseId, string $customerId, string $productId, ?string $expiry, string $direction, float $qty): void
    {
        DB::select('SELECT fn_post_movement(?, ?, ?, ?, ?, ?::date, ?::movement_direction, ?)', [
            $transactionId, $lineId, $warehouseId, $customerId, $productId, $expiry, $direction, $qty,
        ]);
    }

    private function quantity(string|float|int $qty, string $key): float
    {
        $text = trim((string) $qty);

        if (! self::whole($text)) {
            throw new PostingRejected('transactions.form.errors.number', [], $key, 'qty');
        }

        if ((float) $text <= 0) {
            throw new PostingRejected('transactions.form.errors.more_than_zero', [], $key, 'qty');
        }

        if (self::tooLarge($text)) {
            throw self::tooLargeError($key);
        }

        return (float) $text;
    }

    /**
     * Serial-numbered items: whole pieces in the base unit, one distinct serial each.
     *
     * @param  array<string, true>  $seen  serials already on earlier lines of this document
     * @return list<string>
     */
    private function serials(DocumentLine $line, float $qtyBase, array &$seen, string $key): array
    {
        $serials = array_values(array_filter(array_map('trim', $line->serials), fn (string $serial) => $serial !== ''));

        foreach ($serials as $serial) {
            if (isset($seen[$serial])) {
                throw new SerialProblem('stock.errors.serial_twice', ['serial' => $serial], $serial, $key);
            }

            $seen[$serial] = true;
        }

        if (floor($qtyBase) != $qtyBase || count($serials) !== (int) $qtyBase) {
            throw new PostingRejected('stock.errors.serial_count', ['count' => Format::qty($qtyBase)], $key, 'serials');
        }

        return $serials;
    }

    /**
     * The database refuses negative stock with "Negative stock blocked: ...,
     * available=<n>, requested_out=<n>". That should not happen after the checks
     * above, but if it does, the user still gets a InsufficientStock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function translateDatabaseErrors(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Negative stock blocked')) {
                throw $e;
            }

            preg_match('/available=([0-9.]+), requested_out=([0-9.]+)/', $e->getMessage(), $match);

            // Raw database messages never reach the screen (CLAUDE.md "Feedback messages").
            throw new InsufficientStock(
                'stock.errors.not_enough_plain',
                ['qty' => Format::qty((float) ($match[1] ?? 0))],
                (float) ($match[1] ?? 0),
                (float) ($match[2] ?? 0),
            );
        }
    }
}
