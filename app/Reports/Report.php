<?php

namespace App\Reports;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\Product;
use App\Models\User;
use App\Services\Stock\Availability;
use App\Services\Stock\UnitConverter;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * A read-only report (the add-report skill): filters, columns, one query, and
 * how a row is shown. The screen (App\Livewire\Reports\ReportPage) and the
 * Excel / PDF exports (App\Reports\Export\Exporter) use the same query, so an
 * export always has exactly the rows the screen counts.
 *
 * Scoping: queries start from the warehouse- and customer-scoped models
 * (StockBalance, StockMovement, StockTransaction), and the warehouse filter
 * only ever holds warehouses of the user's own (Filter::normalize).
 *
 * A row is a list of cells keyed by column: a string, or an array with
 *   text   what is shown and exported (required)
 *   value  the raw number for Excel (quantities), when different from text
 *   tone   status colour token (completed, pending, rejected, transit, cancelled)
 *   type   transaction_type.code: shown as the type badge
 *   ltr    codes and references, kept left-to-right
 *   strong bold
 *   nowrap kept on one line (quantities in larger units, record names)
 *
 * @phpstan-type Cell string|array{text: string, value?: float|int|string|null, tone?: string, type?: string, ltr?: bool, strong?: bool, nowrap?: bool}
 */
abstract class Report
{
    public const PER_PAGE = 20;

    /** @var array<string, Product> products of the rows being shown, by id */
    protected array $products = [];

    /** URL segment: /reports/<key>. */
    abstract public function key(): string;

    /**
     * @return list<Filter>
     */
    abstract public function filters(): array;

    /**
     * Column key => header. `priority` 2 hides the column on a tablet; `card`
     * says what the cell is on a phone card: 'title', 'badge' (both on its
     * first line) or 'hide'; other cells are "Label  value" lines (x-data-table).
     *
     * @return array<string, array{label: string, align?: string, priority?: int, card?: string}>
     */
    abstract public function columns(): array;

    /**
     * The rows, already scoped and ordered. $filters are normalized.
     *
     * @param  array<string, string>  $filters
     * @return EloquentBuilder<*>|Builder
     */
    abstract protected function query(array $filters, User $user): EloquentBuilder|Builder;

    /**
     * @param  array<string, string>  $filters
     * @return array<string, Cell>
     */
    abstract public function row(object $row, array $filters): array;

    public function permission(): string
    {
        return 'report.view';
    }

    public function icon(): string
    {
        return 'report';
    }

    public function title(): string
    {
        return __('reports.'.$this->langKey().'.title');
    }

    public function description(): string
    {
        return __('reports.'.$this->langKey().'.description');
    }

    /**
     * Where a row leads (the document, the item...), or null.
     */
    public function link(object $row): ?string
    {
        return null;
    }

    /**
     * Figures shown above the table and on the export (opening balance, totals...).
     *
     * @param  array<string, string>  $filters
     * @return list<array{label: string, value: string}>
     */
    public function summary(array $filters, User $user): array
    {
        return [];
    }

    /**
     * Why the report can't run yet (e.g. "Choose an item"), or null.
     *
     * @param  array<string, string>  $filters
     */
    public function missing(array $filters): ?string
    {
        return null;
    }

    /**
     * The "Date from" / "Date to" of the filters (empty when the report has none).
     *
     * @param  array<string, string>  $filters
     */
    public function dates(array $filters): DateRange
    {
        return new DateRange($filters['from'] ?? '', $filters['to'] ?? '');
    }

    /**
     * Why no rows are fetched: the dates are the wrong way round, or missing().
     *
     * @param  array<string, string>  $filters
     */
    public function blocked(array $filters): ?string
    {
        return $this->dates($filters)->invalid() ? __('feedback.date_order') : $this->missing($filters);
    }

    /**
     * @param  array<string, mixed>  $input  raw query parameters
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
     * One page of rows, mapped to cells.
     *
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, array{cells: array<string, Cell>, link: string|null}>
     */
    public function paginate(array $filters, User $user, int $page): LengthAwarePaginator
    {
        if ($this->blocked($filters) !== null) {
            return new LengthAwarePaginator([], 0, static::PER_PAGE, 1);
        }

        $paginator = $this->query($filters, $user)->paginate(static::PER_PAGE, ['*'], 'page', $page);
        $rows = collect($paginator->items());
        $this->loadProducts($rows->pluck('product_id')->all());

        return $paginator->setCollection($rows->map(fn (object $row) => [
            'cells' => $this->row($row, $filters),
            'link' => $this->link($row),
        ]));
    }

    /**
     * Every row, for an export: the same query as the screen, without pages.
     *
     * @param  array<string, string>  $filters
     * @return Collection<int, array<string, Cell>>
     */
    public function rows(array $filters, User $user): Collection
    {
        if ($this->blocked($filters) !== null) {
            return collect();
        }

        $rows = collect($this->query($filters, $user)->get()->all());
        $this->loadProducts($rows->pluck('product_id')->all());

        return $rows->map(fn (object $row) => $this->row($row, $filters))->values();
    }

    /**
     * Filters as "Label: value" for the export header.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function describeFilters(array $filters, User $user): array
    {
        $lines = [];

        foreach ($this->filters() as $filter) {
            $value = $filters[$filter->name] ?? '';

            if ($value === '') {
                continue;
            }

            $shown = $filter->choices($user, $filters)[$value] ?? match ($filter->type) {
                'toggle' => __('reports.filters.yes'),
                'date' => Format::date(CarbonImmutable::parse($value)),
                default => $value,
            };
            $lines[] = __($filter->label).': '.$shown;
        }

        return $lines;
    }

    /**
     * Loads the products of the rows (names, units) once per page.
     *
     * @param  array<array-key, mixed>  $productIds
     */
    protected function loadProducts(array $productIds): void
    {
        $ids = collect($productIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])
            ->findMany($ids)->keyBy('id')->all() + $this->products;
    }

    protected function product(string $id): Product
    {
        return $this->products[$id];
    }

    protected function converter(string $productId): UnitConverter
    {
        return UnitConverter::forProduct($this->product($productId));
    }

    /**
     * A base quantity with its base unit ("64 Piece"); Excel gets the number.
     *
     * @return array{text: string, value: float}
     */
    protected function qtyCell(float|string|null $qty, string $productId, bool $signed = false): array
    {
        $qty = (float) $qty;
        $text = Format::qtyUnit(abs($qty), $this->converter($productId)->base());

        return ['text' => $signed ? ($qty > 0 ? '+' : ($qty < 0 ? '-' : '')).$text : $text, 'value' => $qty];
    }

    /**
     * The same quantity in larger units: "5 Box + 4 Piece".
     */
    protected function breakdown(float|string|null $qty, string $productId): array
    {
        // "5 Box + 4 Piece" reads as one quantity: never split across lines.
        return ['text' => $this->converter($productId)->breakdown((float) $qty), 'nowrap' => true];
    }

    /**
     * Expiry badge: Expired (red), within $days days (amber, with days left),
     * or OK (green); empty when the item has no expiry date.
     *
     * @return Cell
     */
    protected function expiryCell(?string $expiry, string $timezone, int $days = 30): array|string
    {
        if ($expiry === null) {
            return '';
        }

        $date = CarbonImmutable::parse($expiry);
        $left = (int) CarbonImmutable::now($timezone)->startOfDay()->diffInDays($date, false);

        return match (true) {
            $left < 0 => ['text' => __('reports.expiry_badge.expired', ['date' => Format::date($date)]), 'tone' => 'rejected'],
            $left <= $days => ['text' => __('reports.expiry_badge.soon', ['days' => Format::qty($days), 'date' => Format::date($date), 'left' => Format::qty($left)]), 'tone' => 'pending'],
            default => ['text' => __('reports.expiry_badge.ok', ['date' => Format::date($date)]), 'tone' => 'completed'],
        };
    }

    /**
     * A document reference cell (kept left-to-right).
     *
     * @return array{text: string, ltr: true, strong: true}
     */
    protected function referenceCell(string $reference): array
    {
        return ['text' => $reference, 'ltr' => true, 'strong' => true];
    }

    /**
     * The details page of a document, by type and reference.
     */
    protected function documentLink(string $type, string $reference): ?string
    {
        $types = TransactionTypes::all();

        return isset($types[$type]) ? $types[$type]->route('show', $reference) : null;
    }

    /**
     * Limits rows to items whose name (any language) or code contains $search
     * (FR-11.1: every report filterable by product).
     *
     * @template TQuery of EloquentBuilder<*>|Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function whereItem(EloquentBuilder|Builder $query, string $search, string $productColumn): EloquentBuilder|Builder
    {
        if (trim($search) === '') {
            return $query;
        }

        return $query->whereIn($productColumn, Product::query()->search($search)->select('product.id'));
    }

    /**
     * Today in the current warehouse (its time zone).
     */
    protected function today(): CarbonImmutable
    {
        $warehouse = (string) session(EnsureCurrentWarehouse::SESSION_KEY);

        return $warehouse !== '' ? app(Availability::class)->today($warehouse) : CarbonImmutable::today();
    }

    protected function langKey(): string
    {
        return str_replace('-', '_', $this->key());
    }

    /**
     * Ids of the warehouses the warehouse filter stands for: the one chosen, or
     * all of the user's when 'all'.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    protected function warehouseIds(array $filters, User $user): array
    {
        $value = $filters['warehouse'] ?? 'all';

        return $value === 'all' ? $user->warehouseIds() : [$value];
    }
}
