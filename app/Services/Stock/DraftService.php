<?php

namespace App\Services\Stock;

use App\Mail\DraftEmail;
use App\Models\Product;
use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\Exceptions\SerialProblem;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Audit;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftNotifier;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Draft receipts and orders: saving, editing and cancelling them, and
 * confirming them (with or without last changes) through
 * PostingService::confirmDraft(), the only step that touches stock.
 *
 * A draft is checked like the document it becomes, in the draft's
 * warehouse: items of the customer, units, whole quantities,
 * expiry dates, serial numbers, and for an order enough stock now (stock is
 * not reserved: confirming checks again, under locks). Each save runs in one
 * DB::transaction with its audit entry.
 *
 * Who may do what: the type's create permission (or confirm permission) to
 * save and edit, confirm permission to confirm; staff only in their
 * warehouses; a customer user only for their customer, and their branches
 * when linked to some. The same form submitted twice creates one draft.
 */
final class DraftService
{
    /** Longest reference note, in characters (as on the transaction form). */
    public const MAX_NOTE = 200;

    /**
     * Form row key => stock_draft_line.id of the last save, so problems found
     * when confirming (reported by line id) can be shown on the form's row.
     *
     * @var array<string, string>
     */
    private array $lineIds = [];

    /** Whether the last store() changed the draft (an edit can save what was already there). */
    private bool $lastSaveChanged = false;

    public function __construct(
        private readonly Availability $availability,
        private readonly DocumentNumbers $numbers,
        private readonly PostingService $posting,
        private readonly DraftNotifier $notifier,
    ) {}

    /**
     * Creates a draft ($draft null) or replaces an existing one's header and lines.
     *
     * @throws StockException with the form's row key as row; nothing is saved
     */
    public function save(DraftInput $input, User $by, ?StockDraft $draft = null): StockDraft
    {
        $kind = DraftTypes::get($input->type);

        if ($draft === null && ($existing = $this->alreadySaved($input, $by))) {
            return $existing;
        }

        try {
            $saved = DB::transaction(fn () => $this->store($kind, $input, $by, $draft));
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions of the same form at the same moment: the other one saved it.
            return ($draft === null ? $this->alreadySaved($input, $by) : null) ?? throw $e;
        }

        // Tell the people who can confirm the draft: once when it is added, and
        // on each edit that changed something.
        if ($draft === null) {
            $this->notifier->notify($saved, DraftEmail::CREATED);
        } elseif ($this->lastSaveChanged) {
            $this->notifier->notify($saved, DraftEmail::UPDATED);
        }

        return $saved;
    }

    /**
     * Confirms a draft, after saving $changes to it in the same transaction
     * ("Save and confirm": the confirmer corrects quantities first). When the
     * confirmation fails, the changes are not kept either.
     *
     * @param  string|null  $version  stock_draft.updated_at as the user saw it: a draft changed since is not confirmed
     * @return list<StockTransaction> the document created (one, in the draft's warehouse)
     *
     * @throws StockException
     */
    public function confirm(StockDraft $draft, User $by, ?DraftInput $changes = null, ?string $version = null): array
    {
        $this->lineIds = [];

        return DB::transaction(function () use ($draft, $by, $changes, $version) {
            $header = DB::table('stock_draft')->where('id', $draft->id)->lockForUpdate()->first();

            if ($changes !== null) {
                $draft = $this->store(DraftTypes::get($header->transaction_type), $changes, $by, $draft);
            } elseif ($version !== null && $header->status === 'DRAFT' && $version !== $header->updated_at) {
                throw $this->changed($header);
            }

            return $this->posting->confirmDraft($draft, $by);
        });
    }

    /**
     * Cancels a draft that is not confirmed yet; nothing else changes.
     *
     * @throws StockException with field `reason` when the reason is missing
     */
    public function cancel(StockDraft $draft, User $by, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PostingRejected('stock.errors.cancel_reason_required', [], null, 'reason');
        }

        if (mb_strlen($reason) > PostingService::MAX_REASON) {
            throw new PostingRejected('settings.errors.too_long', ['max' => PostingService::MAX_REASON], null, 'reason');
        }

        DB::transaction(function () use ($draft, $by, $reason) {
            $header = DB::table('stock_draft')->where('id', $draft->id)->lockForUpdate()->first();
            $kind = DraftTypes::get($header->transaction_type);

            $this->assertStillDraft($header);

            if (! $by->hasPermission($kind->createPermission()) || ! self::canSee($header, $by)) {
                throw new NotAllowed('drafts.errors.cannot_cancel');
            }

            DB::table('stock_draft')->where('id', $header->id)->update([
                'status' => 'CANCELLED',
                'cancel_reason' => $reason,
                'updated_by' => $by->id,
                'updated_at' => now(),
            ]);

            Audit::record('stock_draft', $header->id, 'CANCEL',
                ['doc_no' => $header->doc_no, 'status' => 'DRAFT'],
                ['doc_no' => $header->doc_no, 'status' => 'CANCELLED', 'reason' => $reason],
                $by->id,
            );
        });

        $this->notifier->notify($draft, DraftEmail::CANCELLED);
    }

    /**
     * The form row a draft line id (from a problem found when confirming) was saved from, if any.
     */
    public function rowOf(?string $lineId): ?string
    {
        $key = $lineId === null ? false : array_search($lineId, $this->lineIds, true);

        return $key === false ? $lineId : (string) $key;
    }

    /**
     * Whether $by may see a draft (header row): the same rule as DraftScope.
     */
    public static function canSee(object $header, User $by): bool
    {
        if ($by->isCustomerUser()) {
            return $by->canUseBranch($header->customer_branch_id, $header->customer_id);
        }

        return $header->created_by === $by->id || $by->canAccessWarehouse((string) $header->warehouse_id);
    }

    private function store(DraftUi $kind, DraftInput $input, User $by, ?StockDraft $draft): StockDraft
    {
        if ($draft !== null) {
            $header = DB::table('stock_draft')->where('id', $draft->id)->lockForUpdate()->first();
            $this->assertStillDraft($header);

            if ($input->version !== null && $input->version !== $header->updated_at) {
                throw $this->changed($header);
            }

            $canEdit = $by->hasPermission($kind->createPermission()) || $by->hasPermission($kind->confirmPermission());

            if (! $canEdit || ! self::canSee($header, $by) || $header->transaction_type !== $kind->code) {
                throw new NotAllowed('drafts.errors.cannot_edit');
            }
        } elseif (! $by->hasPermission($kind->createPermission())) {
            throw new NotAllowed('drafts.errors.cannot_create');
        }

        $this->checkHeader($kind, $input, $by);

        if ($input->lines === []) {
            throw new PostingRejected('transactions.form.add_a_line');
        }

        $lines = $this->prepareLines($kind, $input);
        $values = [
            'customer_id' => $input->customerId,
            'customer_branch_id' => $input->branchId,
            'warehouse_id' => $input->warehouseId,
            'document_date' => $input->date->toDateString(),
            'reference_note' => filled($input->note) ? trim((string) $input->note) : null,
            // Orders only (DraftUi::hasDelivery()).
            'delivery_date' => $kind->hasDelivery() ? $input->deliveryDate?->toDateString() : null,
            'priority' => $kind->hasDelivery() ? $input->priority : null,
            'updated_at' => now(),
        ];

        if ($draft === null) {
            $before = null;
            $id = (string) DB::table('stock_draft')->insertGetId($values + [
                'doc_no' => $this->numbers->nextDraft($kind, CompanyTime::now()->year),
                'transaction_type' => $kind->code,
                'status' => 'DRAFT',
                'client_request_id' => $this->requestId($input),
                'created_by' => $by->id,
            ], 'id');
        } else {
            $id = $draft->id;
            $before = $this->snapshot($id);
            DB::table('stock_draft')->where('id', $id)->update($values + ['updated_by' => $by->id]);
            DB::table('stock_draft_line')->where('draft_id', $id)->delete();
        }

        $lineNo = 0;
        $this->lineIds = [];

        foreach ($lines as $key => $line) {
            $this->lineIds[$key] = (string) DB::table('stock_draft_line')->insertGetId([
                'draft_id' => $id,
                'line_no' => ++$lineNo,
                'product_id' => $line['product']->id,
                'unit_id' => $line['unit_id'],
                'qty_entered' => $line['qty'],
                'qty_base' => $line['qty_base'],
                'expiry_date' => $line['expiry'],
                'serials' => json_encode($line['serials']),
            ], 'id');
        }

        $after = $this->snapshot($id);
        $this->lastSaveChanged = $before !== $after;

        if ($before !== $after) {
            Audit::record('stock_draft', $id, $draft === null ? 'CREATE' : 'UPDATE', $before, $after, $by->id);
        }

        return StockDraft::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Customer (theirs, for a customer user), branch of that customer that the
     * user may use, an active warehouse of theirs, date not in the future, a
     * reference note that fits.
     */
    private function checkHeader(DraftUi $kind, DraftInput $input, User $by): void
    {
        if (! DB::table('customer')->where('id', $input->customerId)->where('active', true)->exists()
            || ($by->isCustomerUser() && $input->customerId !== $by->customer_id)) {
            throw new PostingRejected('stock.errors.customer_inactive', [], null, 'customer');
        }

        if (! DB::table('customer_branch')->where('id', $input->branchId)->where('customer_id', $input->customerId)->where('active', true)->exists()) {
            throw new PostingRejected('drafts.errors.branch_required', [], null, 'branch');
        }

        if (! $by->canUseBranch($input->branchId, $input->customerId)) {
            throw new NotAllowed('drafts.errors.branch_not_allowed', [], null, 'branch');
        }

        if (! DB::table('warehouse')->where('id', $input->warehouseId)->where('active', true)->exists()) {
            throw new PostingRejected('drafts.errors.choose_warehouse', [], null, 'warehouse');
        }

        if (! $by->canAccessWarehouse($input->warehouseId)) {
            throw new NotAllowed('stock.errors.warehouse_not_allowed', [], null, 'warehouse');
        }

        if ($input->date->toDateString() > CompanyTime::now()->toDateString()) {
            throw new PostingRejected('transactions.form.errors.date_future', [], null, 'date');
        }

        if (mb_strlen((string) $input->note) > self::MAX_NOTE) {
            throw new PostingRejected('settings.errors.too_long', ['max' => self::MAX_NOTE], null, 'reference');
        }

        if (! $kind->hasDelivery()) {
            return;
        }

        if ($input->deliveryDate === null) {
            throw new PostingRejected('drafts.errors.delivery_date_required', [], null, 'delivery_date');
        }

        if ($input->deliveryDate->toDateString() < $input->date->toDateString()) {
            throw new PostingRejected('drafts.errors.delivery_date_before', [], null, 'delivery_date');
        }

        if (! array_key_exists((string) $input->priority, DraftTypes::priorityOptions())) {
            throw new PostingRejected('drafts.errors.priority_required', [], null, 'priority');
        }
    }

    /**
     * Checks every line as the document it becomes would; returns what to save.
     *
     * @return array<string, array{product: Product, unit_id: string, qty: int, qty_base: float, expiry: string|null, serials: list<string>}>
     */
    private function prepareLines(DraftUi $kind, DraftInput $input): array
    {
        /** @var Collection<string, Product> $products */
        $products = Product::withoutGlobalScopes()
            ->with(['baseUnit', 'units.unit'])
            ->where('customer_id', $input->customerId)
            ->where('active', true)
            ->findMany(array_map(fn (DraftLineInput $line) => $line->productId, $input->lines))
            ->keyBy('id');
        $seen = [];
        $rows = [];
        $keys = []; // rule 12: line key => [row key, row number]
        $number = 0;

        foreach ($input->lines as $key => $line) {
            $number++;
            $product = $products[$line->productId] ?? throw new PostingRejected('transactions.form.errors.choose_item', [], $key, 'item');
            $converter = $product->converter();

            if ($product->is_service && ! $kind->type->allowsServices()) {
                throw new PostingRejected('transactions.form.errors.service_not_here', [
                    'item' => $product->name(),
                    'type' => $kind->type->label('singular'),
                ], $key, 'item');
            }

            if (! $converter->has($line->unit)) {
                throw new PostingRejected('transactions.form.errors.choose_unit', [], $key, 'unit');
            }

            $qty = trim((string) $line->qty);

            if (! PostingService::whole($qty)) {
                throw new PostingRejected('transactions.form.errors.number', [], $key, 'qty');
            }

            if ((int) $qty <= 0) {
                throw new PostingRejected('transactions.form.errors.more_than_zero', [], $key, 'qty');
            }

            $qtyBase = $converter->toBase($qty, $line->unit);

            if (PostingService::tooLarge($qty, $qtyBase)) {
                throw PostingService::tooLargeError($key);
            }

            if (round($qtyBase, 4) != $qtyBase) {
                throw new PostingRejected('stock.errors.not_exact', [], $key, 'qty');
            }

            if ($product->track_serial && $product->track_expiry) {
                throw new PostingRejected('stock.errors.serial_and_expiry', [], $key, 'item');
            }

            $expiry = null;

            if ($product->track_expiry) {
                $expiry = $this->expiry($line->expiry, $key)
                    ?? throw new PostingRejected($kind->receives() ? 'transactions.form.errors.expiry_required' : 'transactions.form.errors.expiry_choose', [], $key, 'expiry');
            }

            // Rule 12: the lines become one document, which may not repeat a line.
            $lineKey = $kind->type->lineKey($product->id, $line->unit, $expiry, $product->track_expiry);

            if (isset($keys[$lineKey])) {
                [$message, $params] = $kind->type->duplicateMessage($product->name(), $line->unit, $expiry, $product->track_expiry, $keys[$lineKey]['number']);

                throw new DuplicateLine($message, $params, (string) $key, $keys[$lineKey]['key']);
            }

            $keys[$lineKey] = ['key' => (string) $key, 'number' => $number];

            $serials = $product->track_serial ? $this->serials($kind, $product, $line, $input->warehouseId, $qtyBase, $seen, $key) : [];

            // A service has no stock to check.
            if (! $kind->receives() && ! $product->is_service) {
                $this->checkStock($product, $input->warehouseId, $input->customerId, $expiry, $qtyBase, $seen, $key);
            }

            $rows[$key] = [
                'product' => $product,
                'unit_id' => $product->units->firstWhere('unit.code', $line->unit)->unit_id,
                'qty' => (int) $qty,
                'qty_base' => $qtyBase,
                'expiry' => $expiry,
                'serials' => $serials,
            ];
        }

        return $rows;
    }

    /**
     * Serial-numbered items: one distinct serial per piece. Coming in, none may
     * be in stock already; going out, each must be in stock in the draft's warehouse.
     *
     * @param  array<string, mixed>  $seen
     * @return list<string>
     */
    private function serials(DraftUi $kind, Product $product, DraftLineInput $line, string $warehouseId, float $qtyBase, array &$seen, string $key): array
    {
        $serials = array_values(array_filter(array_map('trim', $line->serials), fn (string $serial) => $serial !== ''));

        foreach ($serials as $serial) {
            if (isset($seen['serial|'.$product->id.'|'.$serial])) {
                throw new SerialProblem('stock.errors.serial_twice', ['serial' => $serial], $serial, $key);
            }

            $seen['serial|'.$product->id.'|'.$serial] = true;
        }

        if (floor($qtyBase) != $qtyBase || count($serials) !== (int) $qtyBase) {
            throw new PostingRejected('stock.errors.serial_count', ['count' => Format::qty($qtyBase)], $key, 'serials');
        }

        if ($kind->receives()) {
            $inStock = DB::table('serial_number')
                ->where('product_id', $product->id)
                ->whereIn('serial_no', $serials)
                ->whereIn('status', ['IN_STOCK', 'IN_TRANSIT'])
                ->value('serial_no');

            if ($inStock !== null) {
                throw new SerialProblem('stock.errors.serial_in_stock', ['serial' => $inStock], $inStock, $key);
            }

            return $serials;
        }

        $here = DB::table('serial_number')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'IN_STOCK')
            ->whereIn('serial_no', $serials)
            ->pluck('serial_no')
            ->all();

        foreach ($serials as $serial) {
            if (! in_array($serial, $here, true)) {
                throw new SerialProblem('stock.errors.serial_not_in_stock', ['serial' => $serial], $serial, $key);
            }
        }

        return $serials;
    }

    /**
     * Order: enough in stock now in the draft's warehouse (of the line's expiry
     * date, which must not have expired), after earlier lines of the same item.
     *
     * @param  array<string, mixed>  $seen
     */
    private function checkStock(Product $product, string $warehouseId, string $customerId, ?string $expiry, float $qtyBase, array &$seen, string $key): void
    {
        $seenKey = 'qty|'.$warehouseId.'|'.$product->id.'|'.$expiry;

        if ($expiry !== null) {
            $lot = collect($this->availability->rows($warehouseId, $customerId, $product->id))->firstWhere('expiry', $expiry)
                ?? throw new PostingRejected('transactions.form.errors.expiry_not_here', [], $key, 'expiry');

            if ($lot['expired']) {
                throw new PostingRejected('stock.errors.expiry_expired', ['date' => Format::date(CarbonImmutable::parse($expiry))], $key, 'expiry');
            }

            $available = $lot['qty'];
        } else {
            $available = $this->availability->quantity($warehouseId, $customerId, $product->id, excludeExpired: true);
        }

        $taken = $seen[$seenKey] ?? 0.0;

        if ($available - $taken < $qtyBase - 1e-9) {
            throw Availability::notEnough($product, $warehouseId, max($available - $taken, 0), $qtyBase, row: $key);
        }

        $seen[$seenKey] = $taken + $qtyBase;
    }

    private function expiry(?string $expiry, string $key): ?string
    {
        $expiry = trim((string) $expiry);

        if ($expiry === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $expiry);

        if (! $date || $date->toDateString() !== $expiry) {
            throw new PostingRejected('transactions.form.errors.expiry_invalid', [], $key, 'expiry');
        }

        return $expiry;
    }

    private function assertStillDraft(object $header): void
    {
        if ($header->status === 'DRAFT') {
            return;
        }

        if ($header->status === 'CANCELLED') {
            throw new AlreadyProcessed('drafts.errors.already_cancelled');
        }

        throw new AlreadyProcessed('drafts.errors.already_confirmed', [
            'name' => (string) DB::table('app_user')->where('id', $header->confirmed_by)->value('full_name'),
            'time' => $header->confirmed_at === null ? '' : Format::dateTime(CarbonImmutable::parse($header->confirmed_at)),
        ]);
    }

    /**
     * "Nour changed this draft meanwhile — open it again to see the changes."
     */
    private function changed(object $header): AlreadyProcessed
    {
        return new AlreadyProcessed('drafts.errors.changed', [
            'name' => (string) DB::table('app_user')->where('id', $header->updated_by ?? $header->created_by)->value('full_name'),
        ]);
    }

    /**
     * What audit_log keeps of a draft: header and lines.
     *
     * @return array<string, mixed>
     */
    private function snapshot(string $id): array
    {
        $header = DB::table('stock_draft')->where('id', $id)->first();

        return [
            'doc_no' => $header->doc_no,
            'customer_id' => $header->customer_id,
            'branch_id' => $header->customer_branch_id,
            'warehouse_id' => $header->warehouse_id,
            'date' => $header->document_date,
            'note' => $header->reference_note,
            ...($header->priority === null ? [] : ['delivery_date' => $header->delivery_date, 'priority' => $header->priority]),
            'lines' => DB::table('stock_draft_line as l')
                ->join('unit as u', 'u.id', '=', 'l.unit_id')
                ->where('l.draft_id', $id)
                ->orderBy('l.line_no')
                ->get(['l.product_id', 'u.code as unit', 'l.qty_entered', 'l.expiry_date', 'l.serials'])
                ->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit' => $line->unit,
                    'qty' => (float) $line->qty_entered,
                    'expiry' => $line->expiry_date,
                    'serials' => json_decode((string) $line->serials, true) ?: [],
                ])
                ->all(),
        ];
    }

    private function alreadySaved(DraftInput $input, User $by): ?StockDraft
    {
        $requestId = $this->requestId($input);

        return $requestId === null ? null : StockDraft::withoutGlobalScopes()
            ->where('client_request_id', $requestId)
            ->where('created_by', $by->id)
            ->first();
    }

    private function requestId(DraftInput $input): ?string
    {
        return Str::isUuid($input->requestId) ? strtolower((string) $input->requestId) : null;
    }
}
