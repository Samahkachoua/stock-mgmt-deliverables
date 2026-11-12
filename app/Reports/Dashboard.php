<?php

namespace App\Reports;

use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\PostingService;
use App\Support\CompanyTime;
use App\Support\DateRange;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The owner dashboard (/dashboard, dashboard.view): figures for the business
 * in the warehouses and customer the filters name. Each figure that has a
 * report is that report's row count with the same filters, so opening it
 * shows exactly the rows counted.
 *
 * Quantities of different items (and units) are never added together: the
 * figures are documents, lines, items or days.
 *
 * Current state (orders not delivered yet, expiry, dormant stock) ignores
 * the dates where it says "Now"; the dates limit the work done, by the
 * document's own date (deliveries: the day delivered).
 *
 * @phpstan-import-type Cell from Report
 */
final class Dashboard
{
    /** "Expiring soon" on the dashboard: within this many days. */
    public const EXPIRY_DAYS = 30;

    /** Rows of each short table (the report has the rest). */
    public const TOP_ROWS = 5;

    /** Branches shown in "Orders by branch" before "See all". */
    public const TOP_BRANCHES = 8;

    /** Steps of an order, in order (lang dashboard.branches.stage.*). */
    public const ORDER_STAGES = ['open', 'ready', 'on_way', 'delivered'];

    /**
     * @return list<Filter>
     */
    public function filters(): array
    {
        return [Filter::warehouse(), Filter::customer(), ...Filter::dates()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function normalize(array $input, User $user): array
    {
        $filters = [];

        foreach ($this->filters() as $filter) {
            $filters[$filter->name] = $filter->normalize($input[$filter->name] ?? null, $user, $filters);
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function dates(array $filters): DateRange
    {
        return new DateRange($filters['from'] ?? '', $filters['to'] ?? '');
    }

    /**
     * The filters of report $key that show what the dashboard counts for it.
     *
     * @param  array<string, string>  $filters  the dashboard's, normalized
     * @return array<string, string>
     */
    public function reportFilters(string $key, array $filters, User $user, string $status = 'all'): array
    {
        $preset = $key === 'expiry' ? ['status' => $status, 'days' => (string) self::EXPIRY_DAYS] : [];

        return Reports::get($key)->normalize($preset + $filters, $user);
    }

    /**
     * Rows of report $key with the dashboard's filters: how many, the first
     * few, and the report's "days" filter (expiring within / not moved for).
     *
     * @param  array<string, string>  $filters
     * @return array{total: int, rows: Collection<int, array{cells: array<string, Cell>, link: string|null}>, days: int}
     */
    public function table(string $key, array $filters, User $user): array
    {
        $report = Reports::get($key);
        $reportFilters = $this->reportFilters($key, $filters, $user);

        return [
            'total' => $report->count($reportFilters, $user),
            'rows' => $report->top($reportFilters, $user, self::TOP_ROWS),
            'days' => (int) ($reportFilters['days'] ?? 0),
        ];
    }

    /**
     * Lines of completed documents per warehouse over the period (where the
     * document was recorded: a transfer counts at its source), busiest first.
     *
     * @param  array<string, string>  $filters
     * @return list<array{name: string, lines: int, documents: int}>
     */
    public function byWarehouse(array $filters, User $user): array
    {
        if ($this->dates($filters)->invalid()) {
            return [];
        }

        $counts = $this->completed($filters, $user)
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->groupBy('stock_transaction.warehouse_id')
            ->selectRaw('stock_transaction.warehouse_id, count(*) as lines, count(distinct stock_transaction.id) as documents')
            ->toBase()
            ->get()
            ->keyBy('warehouse_id');

        return $user->assignedWarehouses()
            ->whereIn('id', $this->warehouseIds($filters, $user))
            ->map(fn ($warehouse) => [
                'name' => (string) $warehouse->name,
                'lines' => (int) ($counts[$warehouse->id]->lines ?? 0),
                'documents' => (int) ($counts[$warehouse->id]->documents ?? 0),
            ])
            ->sortByDesc('lines')
            ->values()
            ->all();
    }

    /**
     * Orders per customer branch over the period (by the order's date), split
     * by how far each has got: open (not confirmed), ready to dispatch, on the
     * way, delivered. Cancelled orders (or whose delivery notes were all
     * cancelled) are left out. Late: not delivered, delivery date before today.
     * Grouped by customer (A–Z), busiest branch first within each. Only
     * branches the user may see (DraftScope).
     *
     * @param  array<string, string>  $filters
     * @return list<array{branch_id: string, customer_id: string, branch: string, customer: string, stages: array<string, int>, total: int, late: int}>
     */
    public function ordersByBranch(array $filters, User $user): array
    {
        $kind = self::ordersKind();

        if ($kind === null || $this->dates($filters)->invalid()) {
            return [];
        }

        // Each order's delivery notes (older orders may have several): which steps they are at.
        $notes = DB::table('stock_transaction')
            ->whereNotNull('draft_id')
            ->groupBy('draft_id')
            ->selectRaw("draft_id, bool_or(status = 'CONFIRMED') as ready, bool_or(status = 'DISPATCHED') as on_way, bool_or(status = 'POSTED') as delivered");

        // An order with several notes counts at its least advanced one.
        $stage = "CASE WHEN stock_draft.status = 'DRAFT' THEN 'open' WHEN stock_draft.status = 'POSTED' THEN "
            ."CASE WHEN notes.ready THEN 'ready' WHEN notes.on_way THEN 'on_way' WHEN notes.delivered THEN 'delivered' END END";

        $selects = collect(self::ORDER_STAGES)->map(fn (string $name) => "count(*) FILTER (WHERE {$stage} = '{$name}') as {$name}")->implode(', ');

        $rows = StockDraft::query()
            ->leftJoinSub($notes, 'notes', 'notes.draft_id', '=', 'stock_draft.id')
            ->join('customer_branch as branch', 'branch.id', '=', 'stock_draft.customer_branch_id')
            ->join('customer', 'customer.id', '=', 'stock_draft.customer_id')
            ->where('stock_draft.transaction_type', $kind->code)
            ->whereIn('stock_draft.warehouse_id', $this->warehouseIds($filters, $user))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_draft.customer_id', $filters['customer']))
            ->tap(fn ($query) => $this->dates($filters)->whereDay($query, 'stock_draft.document_date'))
            ->whereRaw("({$stage}) IS NOT NULL")
            ->groupBy('branch.id', 'branch.name', 'customer.id', 'customer.name')
            ->selectRaw("branch.id as branch_id, branch.name as branch_name, customer.id as customer_id, customer.name as customer_name, {$selects}, "
                ."count(*) FILTER (WHERE ({$stage}) <> 'delivered' AND stock_draft.delivery_date < ?) as late", [CompanyTime::today()->toDateString()])
            ->toBase()
            ->get();

        return $rows
            ->map(function ($row) {
                $stages = collect(self::ORDER_STAGES)->mapWithKeys(fn (string $name) => [$name => (int) $row->{$name}])->all();

                return [
                    'branch_id' => (string) $row->branch_id,
                    'customer_id' => (string) $row->customer_id,
                    'branch' => (string) $row->branch_name,
                    'customer' => (string) $row->customer_name,
                    'stages' => $stages,
                    'total' => array_sum($stages),
                    'late' => (int) $row->late,
                ];
            })
            // Each customer on its own (A–Z), its branches busiest first: branches of different customers are never ranked together.
            ->sort(fn (array $a, array $b) => [$a['customer'], $a['customer_id'], $b['total'], $a['branch']] <=> [$b['customer'], $b['customer_id'], $a['total'], $b['branch']])
            ->values()
            ->all();
    }

    /**
     * Delivery note lines delivered short per customer branch, by reason, over
     * the period (by the day delivered, as the Delivery discrepancies report
     * counts them), and the share of the branch's delivered lines that were
     * short. Branches with nothing short are left out. Grouped by customer
     * (A–Z), most short first within each.
     *
     * @param  array<string, string>  $filters
     * @return list<array{branch_id: string, customer_id: string, branch: string, customer: string, reasons: array<string, int>, short: int, delivered: int, rate: int}>
     */
    public function discrepanciesByBranch(array $filters, User $user): array
    {
        if ($this->dates($filters)->invalid()) {
            return [];
        }

        $selects = collect(PostingService::DELIVERY_REASONS)
            ->map(fn (string $reason, int $i) => "count(*) FILTER (WHERE l.shortage_reason = '{$reason}') as reason_{$i}")
            ->implode(', ');

        $rows = StockTransaction::query()
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('customer_branch as branch', 'branch.id', '=', 'stock_transaction.customer_branch_id')
            ->join('customer', 'customer.id', '=', 'stock_transaction.customer_id')
            ->where('stock_transaction.transaction_type', 'OUT')
            ->where('stock_transaction.status', 'POSTED')
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds($filters, $user))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->when($user->branchIds() !== [], fn ($query) => $query->whereIn('stock_transaction.customer_branch_id', $user->branchIds()))
            ->tap(fn ($query) => $this->dates($filters)->whereInstant($query, 'stock_transaction.delivered_at', CompanyTime::zone()))
            ->groupBy('branch.id', 'branch.name', 'customer.id', 'customer.name')
            ->havingRaw('count(l.shortage_reason) > 0')
            ->selectRaw('branch.id as branch_id, branch.name as branch_name, customer.id as customer_id, customer.name as customer_name, '
                ."{$selects}, count(*) FILTER (WHERE l.replaces_line_id IS NULL) as delivered")
            ->toBase()
            ->get();

        return $rows
            ->map(function ($row) {
                $reasons = [];
                foreach (PostingService::DELIVERY_REASONS as $i => $reason) {
                    $reasons[$reason] = (int) $row->{'reason_'.$i};
                }
                $short = array_sum($reasons);
                $delivered = (int) $row->delivered;

                return [
                    'branch_id' => (string) $row->branch_id,
                    'customer_id' => (string) $row->customer_id,
                    'branch' => (string) $row->branch_name,
                    'customer' => (string) $row->customer_name,
                    'reasons' => $reasons,
                    'short' => $short,
                    'delivered' => $delivered,
                    'rate' => $delivered > 0 ? (int) round($short / $delivered * 100) : 0,
                ];
            })
            // Each customer on its own (A–Z), its branches with the most short first.
            ->sort(fn (array $a, array $b) => [$a['customer'], $a['customer_id'], $b['short'], $a['branch']] <=> [$b['customer'], $b['customer_id'], $a['short'], $b['branch']])
            ->values()
            ->all();
    }

    /**
     * The kind of draft that is an order (it has a delivery date), if any.
     */
    public static function ordersKind(): ?DraftUi
    {
        return collect(DraftTypes::all())->first(fn (DraftUi $kind) => $kind->hasDelivery());
    }

    /**
     * Lines of completed documents per customer over the period, counted as
     * byWarehouse() counts them, split by kind of work (typeGroups()). Each
     * customer stands on its own: listed A–Z, never ranked against the others
     * or shown as a share of their work.
     *
     * @param  array<string, string>  $filters
     * @return list<array{customer_id: string, customer: string, groups: array<string, int>, lines: int, documents: int}>
     */
    public function byCustomer(array $filters, User $user): array
    {
        if ($this->dates($filters)->invalid()) {
            return [];
        }

        $groups = $this->typeGroups();
        $query = $this->completed($filters, $user)
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('customer', 'customer.id', '=', 'stock_transaction.customer_id')
            ->groupBy('customer.id', 'customer.name')
            ->orderBy('customer.name')
            ->orderBy('customer.id')
            ->selectRaw('customer.id as customer_id, customer.name as customer_name, count(*) as lines, count(distinct stock_transaction.id) as documents');

        foreach ($groups as $group => $types) {
            $codes = array_map(fn (TypeUi $type) => $type->code, $types);
            $marks = implode(', ', array_fill(0, count($codes), '?'));
            $query->selectRaw("count(*) FILTER (WHERE stock_transaction.transaction_type IN ({$marks})) as group_{$group}", $codes);
        }

        return $query->toBase()->get()
            ->map(fn ($row) => [
                'customer_id' => (string) $row->customer_id,
                'customer' => (string) $row->customer_name,
                'groups' => collect($groups)->map(fn ($types, string $group) => (int) $row->{'group_'.$group})->all(),
                'lines' => (int) $row->lines,
                'documents' => (int) $row->documents,
            ])
            ->values()
            ->all();
    }

    /**
     * Kinds of work, from each type's own data (never its code): transfers
     * (dispatch then receive), stock in, stock out, counts (direction decided
     * per line). Each with its types in sidebar order; empty kinds left out.
     *
     * @return array<string, non-empty-list<TypeUi>>
     */
    public function typeGroups(): array
    {
        $groups = ['in' => [], 'out' => [], 'transfers' => [], 'counts' => []];

        foreach ($this->types() as $type) {
            $group = match (true) {
                $type->dispatchReceive() => 'transfers',
                $type->postingDirection() === 'IN' => 'in',
                $type->postingDirection() === 'OUT' => 'out',
                default => 'counts',
            };
            $groups[$group][] = $type;
        }

        return array_filter($groups);
    }

    /**
     * Documents of the warehouses and customer the filters name (any status, any date).
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockTransaction>
     */
    private function documents(array $filters, User $user): Builder
    {
        return StockTransaction::query()
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds($filters, $user))
            ->whereIn('stock_transaction.transaction_type', $this->types()->keys()->all())
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']));
    }

    /**
     * Documents that changed stock (posted, or dispatched and on their way), in the period.
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockTransaction>
     */
    private function completed(array $filters, User $user): Builder
    {
        return $this->documents($filters, $user)
            ->whereIn('stock_transaction.status', DB::table('transaction_status')->where('affects_stock', true)->select('code'))
            ->tap(fn ($query) => $this->dates($filters)->whereDay($query, 'stock_transaction.document_date'));
    }

    /**
     * The types with screens, in sidebar order (config/transactions.php).
     *
     * @return Collection<string, TypeUi>
     */
    private function types(): Collection
    {
        $order = array_keys(config('transactions.types'));

        return collect(TransactionTypes::all())->sortBy(fn (TypeUi $type) => array_search($type->code, $order, true));
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function warehouseIds(array $filters, User $user): array
    {
        return ($filters['warehouse'] ?? 'all') === 'all' ? $user->warehouseIds() : [$filters['warehouse']];
    }
}
