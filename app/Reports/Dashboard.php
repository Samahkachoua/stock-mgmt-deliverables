<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Availability;
use App\Support\DateRange;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Carbon\CarbonImmutable;
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
 * Current state (pending approvals, transfers in transit, expiry, dormant
 * stock) ignores the dates; the dates limit the work done (documents, lines,
 * damage, adjustments), by the document's own date.
 *
 * @phpstan-import-type Cell from Report
 */
final class Dashboard
{
    /** "Expiring soon" on the dashboard: within this many days. */
    public const EXPIRY_DAYS = 30;

    /** A transfer on its way longer than this is late. */
    public const LATE_TRANSIT_DAYS = 7;

    /** Rows of each short table (the report has the rest). */
    public const TOP_ROWS = 5;

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
     * The figures at the top, keyed by tile.
     *
     * @param  array<string, string>  $filters
     * @return array{
     *     pending: array{count: int, oldest_days: int|null, type: string|null},
     *     transit: array{count: int, late: int, oldest_days: int|null},
     *     expiry: array{count: int, expired: int},
     *     damage: array{lines: int},
     *     adjustments: array{lines: int},
     * }
     */
    public function tiles(array $filters, User $user): array
    {
        return [
            'pending' => $this->pending($filters, $user),
            'transit' => $this->transit($filters, $user),
            'expiry' => [
                'count' => $this->reportCount('expiry', $filters, $user),
                'expired' => Reports::get('expiry')->count($this->reportFilters('expiry', $filters, $user, 'expired'), $user),
            ],
            'damage' => ['lines' => $this->reportCount('damage', $filters, $user)],
            'adjustments' => ['lines' => $this->reportCount('adjustments', $filters, $user)],
        ];
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
     * Completed documents per type over the period, in steps of a day, a month
     * or a year (whichever keeps the chart readable). Without dates: from the
     * first document to today.
     *
     * @param  array<string, string>  $filters
     * @return array{step: string, buckets: list<string>, series: array<string, array{type: TypeUi, total: int, counts: array<string, int>}>}
     */
    public function activity(array $filters, User $user): array
    {
        $types = $this->types();
        $documents = $this->completed($filters, $user);
        $dates = $this->dates($filters);

        $first = $dates->from !== '' ? $dates->from : (clone $documents)->min('stock_transaction.document_date');
        $last = $dates->to !== '' ? $dates->to : max($this->today($user)->toDateString(), (string) (clone $documents)->max('stock_transaction.document_date'));

        $series = $types->map(fn (TypeUi $type) => ['type' => $type, 'total' => 0, 'counts' => []])->all();

        if ($first === null || $dates->invalid()) {
            return ['step' => 'day', 'buckets' => [], 'series' => $series];
        }

        $start = CarbonImmutable::parse($first);
        $end = CarbonImmutable::parse($last);
        $step = match (true) {
            $start->diffInDays($end) < 31 => 'day',
            $start->diffInMonths($end) < 24 => 'month',
            default => 'year',
        };

        $buckets = [];
        $bucket = match ($step) {
            'day' => $start,
            'month' => $start->startOfMonth(),
            default => $start->startOfYear(),
        };

        while ($bucket <= $end) {
            $buckets[] = $bucket->toDateString();
            $bucket = match ($step) {
                'day' => $bucket->addDay(),
                'month' => $bucket->addMonthNoOverflow(),
                default => $bucket->addYear(),
            };
        }

        $counts = $documents
            ->selectRaw("date_trunc('{$step}', stock_transaction.document_date)::date as bucket, stock_transaction.transaction_type, count(*) as documents")
            ->groupByRaw('1, 2')
            ->toBase()
            ->get();

        foreach ($counts as $row) {
            if (isset($series[$row->transaction_type])) {
                $series[$row->transaction_type]['counts'][$row->bucket] = (int) $row->documents;
                $series[$row->transaction_type]['total'] += (int) $row->documents;
            }
        }

        return ['step' => $step, 'buckets' => $buckets, 'series' => $series];
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
     * Documents waiting for approval (anyone's), and how long the oldest has waited.
     *
     * @param  array<string, string>  $filters
     * @return array{count: int, oldest_days: int|null, type: string|null}
     */
    private function pending(array $filters, User $user): array
    {
        $oldest = $this->documents($filters, $user)
            ->where('status', 'PENDING_APPROVAL')
            ->orderBy('created_at')
            ->toBase()
            ->first(['transaction_type', 'created_at']);

        return [
            'count' => $this->documents($filters, $user)->where('status', 'PENDING_APPROVAL')->count(),
            'oldest_days' => $oldest ? $this->daysSince($oldest->created_at) : null,
            'type' => $oldest?->transaction_type,
        ];
    }

    /**
     * Transfers dispatched and not received yet, from or to the warehouses.
     *
     * @param  array<string, string>  $filters
     * @return array{count: int, late: int, oldest_days: int|null}
     */
    private function transit(array $filters, User $user): array
    {
        $codes = $this->types()->filter(fn (TypeUi $type) => $type->dispatchReceive())->keys()->all();
        $warehouses = $this->warehouseIds($filters, $user);

        $query = fn () => StockTransaction::query()
            ->whereIn('transaction_type', $codes)
            ->where('status', 'DISPATCHED')
            ->where(fn ($query) => $query->whereIn('warehouse_id', $warehouses)->orWhereIn('to_warehouse_id', $warehouses))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('customer_id', $filters['customer']));

        $oldest = $query()->min('dispatched_at');

        return [
            'count' => $query()->count(),
            'late' => $query()->where('dispatched_at', '<', now()->subDays(self::LATE_TRANSIT_DAYS))->count(),
            'oldest_days' => $oldest !== null ? $this->daysSince($oldest) : null,
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function reportCount(string $key, array $filters, User $user): int
    {
        return Reports::get($key)->count($this->reportFilters($key, $filters, $user), $user);
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

    private function daysSince(mixed $instant): int
    {
        return (int) CarbonImmutable::parse($instant)->diffInDays(now());
    }

    /**
     * Today in the user's default warehouse (its time zone).
     */
    private function today(User $user): CarbonImmutable
    {
        $warehouse = $user->defaultWarehouseId();

        return $warehouse !== null ? app(Availability::class)->today($warehouse) : CarbonImmutable::today();
    }
}
