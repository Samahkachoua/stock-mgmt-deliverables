<?php

namespace App\Support\Home;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\CompanyTime;
use App\Support\Days;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Home for warehouse staff (owner, manager, operator…): what needs them now in
 * the chosen warehouses, a short work queue and a few figures. Each card only
 * for users holding the permission of the page it opens; every query goes
 * through the scoped models (warehouse and customer scopes).
 *
 * @phpstan-type Card array{key: string, icon: string, tone: string, chip: string, count: int, label: string, subtitle: string, action: string}
 * @phpstan-type QueueRow array{number: string, kind: TypeUi|DraftUi, draft: bool, status: string, line: string, late: bool, url: string}
 * @phpstan-type Glance array{key: string, label: string, value: string, unit: string, note: string, alert: bool}
 */
final class StaffHome
{
    /** "Expiring" on Home: within this many days (as on the dashboard). */
    public const EXPIRY_DAYS = 30;

    /** Rows of "Today's work queue". */
    public const QUEUE_ROWS = 6;

    private readonly CarbonImmutable $today;

    /**
     * @param  list<string>  $warehouseIds  the warehouses Home shows (one, or all the user's)
     */
    public function __construct(private readonly User $user, private readonly array $warehouseIds)
    {
        $this->today = CompanyTime::today();
    }

    /**
     * "Needs your attention": cards in order of urgency, zero counts included
     * (the view names those in the "All caught up on" line).
     *
     * @return list<Card>
     */
    public function cards(): array
    {
        $cards = [];
        $note = $this->deliveryNotes();
        $orders = DraftTypes::all()['OUT'] ?? null;
        $receipts = DraftTypes::all()['IN'] ?? null;

        // Delivery notes on the road, then ready to go: whoever dispatches and delivers them.
        if ($note !== null && Gate::forUser($this->user)->allows($note->createPermission())) {
            $cards[] = $this->dispatchedCard($note);
            $cards[] = $this->confirmedCard($note);
        }

        if ($orders !== null && $this->user->hasPermission($orders->confirmPermission())) {
            $cards[] = $this->draftCard($orders, 'orders', 'pending');
        }

        foreach ($this->approvableTypes() as $type) {
            $cards[] = $this->approvalCard($type);
        }

        $transfer = TransactionTypes::all()['TRANSFER'] ?? null;
        if ($transfer !== null && $this->user->hasPermission('stock.transfer.receive')) {
            $cards[] = $this->arrivingCard($transfer);
        }

        if ($receipts !== null && $this->user->hasPermission($receipts->confirmPermission())) {
            $cards[] = $this->draftCard($receipts, 'receipts', 'pending');
        }

        return $cards;
    }

    /**
     * "Today's work queue": the documents and drafts to act on, most urgent
     * first (late deliveries, orders due, notes to dispatch, approvals,
     * transfers arriving), at most QUEUE_ROWS.
     *
     * @return list<QueueRow>
     */
    public function queue(): array
    {
        $rows = [];
        $note = $this->deliveryNotes();

        if ($note !== null && Gate::forUser($this->user)->allows($note->createPermission()) && Gate::forUser($this->user)->allows($note->viewGate())) {
            foreach ($this->documents($note, 'DISPATCHED')->orderBy('stock_transaction.dispatched_at')->limit(self::QUEUE_ROWS)->get() as $row) {
                $days = $this->daysSince($row->getAttribute('dispatched_at'));
                $rows[] = [0, $days, $this->documentRow($note, $row, __('home.queue.awaiting_delivery', ['customer' => $row->getAttribute('customer_name'), 'days' => $this->days($days)]), $days > 0)];
            }

            foreach ($this->documents($note, 'CONFIRMED')->orderBy('stock_transaction.document_date')->limit(self::QUEUE_ROWS)->get() as $row) {
                $rows[] = [2, $this->daysSince($row->getAttribute('document_date')), $this->documentRow($note, $row, __('home.queue.ready_to_dispatch', ['customer' => $row->getAttribute('customer_name'), 'lines' => $this->lines($row->getAttribute('line_count'))]), false)];
            }
        }

        $orders = DraftTypes::all()['OUT'] ?? null;
        if ($orders !== null && $this->user->hasPermission($orders->confirmPermission())) {
            $drafts = $this->drafts($orders)
                ->orderByRaw("CASE stock_draft.priority WHEN 'URGENT' THEN 0 WHEN 'NORMAL' THEN 1 ELSE 2 END")
                ->orderBy('stock_draft.delivery_date')
                ->limit(self::QUEUE_ROWS)
                ->get();

            foreach ($drafts as $row) {
                $due = $row->getAttribute('delivery_date') !== null ? Days::between($this->today, (string) $row->getAttribute('delivery_date')) : null;
                $line = match (true) {
                    $due === null => __('home.queue.order', ['customer' => $row->getAttribute('customer_name'), 'lines' => $this->lines($row->getAttribute('line_count'))]),
                    $due < 0 => __('home.queue.order_late', ['customer' => $row->getAttribute('customer_name'), 'lines' => $this->lines($row->getAttribute('line_count')), 'days' => $this->days(-$due)]),
                    $due === 0 => __('home.queue.order_today', ['customer' => $row->getAttribute('customer_name'), 'lines' => $this->lines($row->getAttribute('line_count'))]),
                    default => __('home.queue.order_due', ['customer' => $row->getAttribute('customer_name'), 'lines' => $this->lines($row->getAttribute('line_count')), 'date' => Format::date(CarbonImmutable::parse($row->getAttribute('delivery_date')))]),
                };
                // Late or due today comes right after late deliveries; later orders wait their turn.
                $rows[] = [$due !== null && $due <= 0 ? 1 : 5, $due ?? 0, $this->draftRow($orders, $row, $line, $due !== null && $due < 0)];
            }
        }

        foreach ($this->approvableTypes() as $type) {
            if (! Gate::forUser($this->user)->allows($type->viewGate())) {
                continue;
            }

            foreach ($this->pending($type)->orderBy('stock_transaction.created_at')->limit(self::QUEUE_ROWS)->get() as $row) {
                $days = $this->daysSince($row->getAttribute('created_at'));
                $rows[] = [3, -$days, $this->documentRow($type, $row, __('home.queue.to_approve', ['customer' => $row->getAttribute('customer_name'), 'days' => $this->days($days)]), false)];
            }
        }

        $transfer = TransactionTypes::all()['TRANSFER'] ?? null;
        if ($transfer !== null && $this->user->hasPermission('stock.transfer.receive') && Gate::forUser($this->user)->allows($transfer->viewGate())) {
            foreach ($this->arriving()->join('warehouse as source', 'source.id', '=', 'stock_transaction.warehouse_id')->addSelect('source.name as source_name')->orderBy('stock_transaction.dispatched_at')->limit(self::QUEUE_ROWS)->get() as $row) {
                $days = $this->daysSince($row->getAttribute('dispatched_at'));
                $rows[] = [4, -$days, $this->documentRow($transfer, $row, __('home.queue.arriving', ['warehouse' => $row->getAttribute('source_name'), 'days' => $this->days($days)]), false)];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn (array $row) => $row[2], array_slice($rows, 0, self::QUEUE_ROWS));
    }

    /**
     * "<Warehouse> at a glance": items in stock, open orders, lots expiring
     * soon. Never a sum of quantities of different items.
     *
     * @return list<Glance>
     */
    public function glance(): array
    {
        if (! $this->user->hasPermission('stock.view')) {
            return [];
        }

        $stock = StockBalance::query()->whereIn('stock_balance.warehouse_id', $this->warehouseIds)->where('stock_balance.qty_base', '>', 0);
        $items = (clone $stock)->distinct()->count('stock_balance.product_id');
        $customers = (clone $stock)->distinct()->count('stock_balance.customer_id');

        $figures = [[
            'key' => 'items',
            'label' => __('home.glance.items'),
            'value' => Format::qty($items),
            'unit' => '',
            'note' => trans_choice('home.glance.items_note', $customers, ['count' => Format::qty($customers)]),
            'alert' => false,
        ]];

        $open = $this->openOrders();
        if ($open !== null) {
            $figures[] = [
                'key' => 'orders',
                'label' => __('home.glance.orders'),
                'value' => Format::qty(array_sum($open)),
                'unit' => '',
                'note' => __('home.glance.orders_note', array_map(fn (int $count) => Format::qty($count), $open)),
                'alert' => false,
            ];
        }

        $expiring = self::expiring($this->warehouseIds, self::EXPIRY_DAYS);
        $figures[] = [
            'key' => 'expiring',
            'label' => __('home.glance.expiring', ['days' => Format::qty(self::EXPIRY_DAYS)]),
            'value' => Format::qty($expiring['lots']),
            'unit' => trans_choice('home.glance.lots', $expiring['lots']),
            'note' => $expiring['first'] === null ? __('home.glance.expiring_none') : __('home.glance.expiring_first', $expiring['first']),
            'alert' => $expiring['lots'] > 0,
        ];

        return $figures;
    }

    /**
     * Lots (expiry date + batch) with stock that expire from today to
     * $days days ahead, and the first of them.
     *
     * @param  list<string>  $warehouseIds
     * @return array{lots: int, first: array{date: string, item: string}|null}
     */
    public static function expiring(array $warehouseIds, int $days): array
    {
        $today = CompanyTime::today();
        $query = StockBalance::query()
            ->whereIn('stock_balance.warehouse_id', $warehouseIds)
            ->where('stock_balance.qty_base', '>', 0)
            ->whereNotNull('stock_balance.expiry_date')
            ->whereBetween('stock_balance.expiry_date', [$today->toDateString(), $today->addDays($days)->toDateString()]);

        $lots = (clone $query)->count();
        $first = $lots === 0 ? null : (clone $query)->orderBy('stock_balance.expiry_date')->first(['stock_balance.product_id', 'stock_balance.expiry_date']);
        $product = $first === null ? null : Product::query()->find($first->product_id);

        return [
            'lots' => $lots,
            'first' => $first === null ? null : [
                'date' => Format::date(CarbonImmutable::parse($first->expiry_date)),
                'item' => $product?->name() ?? '',
            ],
        ];
    }

    /**
     * Orders not delivered yet in these warehouses: to confirm, to dispatch,
     * on the way; null when the user may open neither orders nor delivery notes.
     *
     * @return array{confirm: int, dispatch: int, transit: int}|null
     */
    private function openOrders(): ?array
    {
        $orders = DraftTypes::all()['OUT'] ?? null;
        $note = $this->deliveryNotes();
        $seeOrders = $orders !== null && Gate::forUser($this->user)->allows('drafts.out.open');
        $seeNotes = $note !== null && Gate::forUser($this->user)->allows($note->viewGate());

        if (! $seeOrders && ! $seeNotes) {
            return null;
        }

        return [
            'confirm' => $seeOrders ? $this->drafts($orders)->count() : 0,
            'dispatch' => $seeNotes ? $this->documents($note, 'CONFIRMED')->count() : 0,
            'transit' => $seeNotes ? $this->documents($note, 'DISPATCHED')->count() : 0,
        ];
    }

    /**
     * @return Card
     */
    private function dispatchedCard(TypeUi $note): array
    {
        $count = $this->documents($note, 'DISPATCHED')->count();
        $oldest = $count === 0 ? null : $this->documents($note, 'DISPATCHED')->orderBy('stock_transaction.dispatched_at')->first();
        $days = $oldest === null ? 0 : $this->daysSince($oldest->getAttribute('dispatched_at'));

        return $this->card('dispatched', 'local_shipping', $days > 0 ? 'rejected' : 'transit', $days > 0 ? 'overdue' : 'on_way', $count,
            __('home.cards.dispatched'),
            $oldest === null ? '' : __('home.cards.dispatched_note', ['number' => $oldest->doc_no, 'days' => $this->days($days)]),
            'review');
    }

    /**
     * @return Card
     */
    private function confirmedCard(TypeUi $note): array
    {
        $count = $this->documents($note, 'CONFIRMED')->count();
        $oldest = $count === 0 ? null : $this->documents($note, 'CONFIRMED')->orderBy('stock_transaction.document_date')->first();

        return $this->card('to_dispatch', 'outbox', 'pending', 'waiting', $count, __('home.cards.to_dispatch'),
            $oldest === null ? '' : __('home.cards.oldest_waiting', ['days' => $this->days($this->daysSince($oldest->getAttribute('document_date')))]),
            'open_list');
    }

    /**
     * @return Card
     */
    private function draftCard(DraftUi $kind, string $key, string $tone): array
    {
        $count = $this->drafts($kind)->count();
        $oldest = $count === 0 ? null : $this->drafts($kind)->orderBy('stock_draft.created_at')->first();

        return $this->card($key, $kind->icon(), $tone, 'waiting', $count, __('home.cards.'.$key),
            $oldest === null ? '' : __('home.cards.oldest_waiting', ['days' => $this->days($this->daysSince($oldest->getAttribute('created_at')))]),
            'open_list');
    }

    /**
     * @return Card
     */
    private function approvalCard(TypeUi $type): array
    {
        $count = $this->pending($type)->count();
        $oldest = $count === 0 ? null : $this->pending($type)->orderBy('stock_transaction.created_at')->first();

        return $this->card('approve.'.$type->code, 'verified_user', 'pending', 'waiting', $count, $type->label('to_approve'),
            $oldest === null ? '' : __('home.cards.oldest_waiting', ['days' => $this->days($this->daysSince($oldest->getAttribute('created_at')))]),
            'approve');
    }

    /**
     * @return Card
     */
    private function arrivingCard(TypeUi $transfer): array
    {
        $count = $this->arriving()->count();
        $oldest = $count === 0 ? null : $this->arriving()->orderBy('stock_transaction.dispatched_at')->first();

        return $this->card('arriving', 'local_shipping', 'transit', 'incoming', $count, __('home.arriving'),
            $oldest === null ? '' : __('home.cards.arriving_note', ['days' => $this->days($this->daysSince($oldest->getAttribute('dispatched_at')))]),
            'track');
    }

    /**
     * @return Card
     */
    private function card(string $key, string $icon, string $tone, string $chip, int $count, string $label, string $subtitle, string $action): array
    {
        return [
            'key' => $key,
            'icon' => $icon,
            'tone' => $tone,
            'chip' => __('home.chips.'.$chip),
            'count' => $count,
            'label' => $label,
            'subtitle' => $subtitle,
            'action' => __('home.actions.'.$action),
        ];
    }

    /**
     * Types whose approve permission the user holds (they never approve their own).
     *
     * @return list<TypeUi>
     */
    private function approvableTypes(): array
    {
        $order = array_keys(config('transactions.types'));

        return collect(TransactionTypes::all())
            ->filter(fn (TypeUi $type) => $type->approvePermission() !== null && $this->user->hasPermission($type->approvePermission()))
            ->sortBy(fn (TypeUi $type) => array_search($type->code, $order, true))
            ->values()
            ->all();
    }

    /**
     * Documents of $type in $status in Home's warehouses, with their customer and line count.
     *
     * @return Builder<StockTransaction>
     */
    private function documents(TypeUi $type, string $status): Builder
    {
        return $this->withDocumentColumns(StockTransaction::query()
            ->where('stock_transaction.transaction_type', $type->code)
            ->where('stock_transaction.status', $status)
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds));
    }

    /**
     * @return Builder<StockTransaction>
     */
    private function pending(TypeUi $type): Builder
    {
        return $this->documents($type, 'PENDING_APPROVAL')->where('stock_transaction.created_by', '<>', $this->user->id);
    }

    /**
     * Transfers on their way to Home's warehouses.
     *
     * @return Builder<StockTransaction>
     */
    private function arriving(): Builder
    {
        return $this->withDocumentColumns(StockTransaction::query()
            ->where('stock_transaction.transaction_type', 'TRANSFER')
            ->where('stock_transaction.status', 'DISPATCHED')
            ->whereIn('stock_transaction.to_warehouse_id', $this->warehouseIds));
    }

    /**
     * @param  Builder<StockTransaction>  $query
     * @return Builder<StockTransaction>
     */
    private function withDocumentColumns(Builder $query): Builder
    {
        return $query
            ->leftJoin('customer', 'customer.id', '=', 'stock_transaction.customer_id')
            ->select([
                'stock_transaction.id', 'stock_transaction.doc_no', 'stock_transaction.transaction_type', 'stock_transaction.status',
                'stock_transaction.document_date', 'stock_transaction.created_at', 'stock_transaction.dispatched_at', 'customer.name as customer_name',
            ])
            ->selectSub(DB::table('stock_transaction_line')->whereColumn('transaction_id', 'stock_transaction.id')->selectRaw('count(*)'), 'line_count');
    }

    /**
     * Open drafts of $kind (not confirmed or cancelled yet) for Home's
     * warehouses, with their customer and line count.
     *
     * @return Builder<StockDraft>
     */
    private function drafts(DraftUi $kind): Builder
    {
        return StockDraft::query()
            ->join('customer', 'customer.id', '=', 'stock_draft.customer_id')
            ->where('stock_draft.transaction_type', $kind->code)
            ->where('stock_draft.status', 'DRAFT')
            ->whereIn('stock_draft.warehouse_id', $this->warehouseIds)
            ->select([
                'stock_draft.id', 'stock_draft.doc_no', 'stock_draft.status', 'stock_draft.delivery_date',
                'stock_draft.priority', 'stock_draft.created_at', 'customer.name as customer_name',
            ])
            ->selectSub(DB::table('stock_draft_line')->whereColumn('draft_id', 'stock_draft.id')->selectRaw('count(*)'), 'line_count');
    }

    /**
     * @return QueueRow
     */
    private function documentRow(TypeUi $type, StockTransaction $row, string $line, bool $late): array
    {
        return [
            'number' => (string) $row->doc_no,
            'kind' => $type,
            'draft' => false,
            'status' => (string) $row->status,
            'line' => $line,
            'late' => $late,
            'url' => $type->route('show', $row->doc_no),
        ];
    }

    /**
     * @return QueueRow
     */
    private function draftRow(DraftUi $kind, StockDraft $row, string $line, bool $late): array
    {
        return [
            'number' => (string) $row->doc_no,
            'kind' => $kind,
            'draft' => true,
            'status' => (string) $row->status,
            'line' => $line,
            'late' => $late,
            'url' => $kind->route('show', $row->doc_no),
        ];
    }

    private function deliveryNotes(): ?TypeUi
    {
        $type = TransactionTypes::all()['OUT'] ?? null;

        return $type !== null && $type->deliverySteps() ? $type : null;
    }

    /**
     * Whole days from $moment (an instant or a date) to today, in the company time zone.
     */
    private function daysSince(DateTimeInterface|string|null $moment): int
    {
        if ($moment === null) {
            return 0;
        }

        $day = is_string($moment) && strlen($moment) === 10 ? $moment : CompanyTime::local($moment)?->toDateString();

        return $day === null ? 0 : max(0, Days::between($day, $this->today));
    }

    private function days(int $days): string
    {
        return trans_choice('home.days', $days, ['count' => Format::qty($days)]);
    }

    private function lines(mixed $lines): string
    {
        return trans_choice('home.lines', (int) $lines, ['count' => Format::qty((int) $lines)]);
    }
}
