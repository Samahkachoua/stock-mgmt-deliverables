<?php

namespace App\Reports;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stock by expiry (sidebar, under Stock): the Stock page split per lot — one
 * row per item, customer, warehouse, expiry date and batch, expired or not.
 * Items without expiry tracking keep one row with no batch or date.
 * From stock_balance (current state).
 */
final class StockByExpiryReport extends Report
{
    public function key(): string
    {
        return 'stock-by-expiry';
    }

    public function icon(): string
    {
        return 'calendar_today';
    }

    public function filters(): array
    {
        return [
            Filter::search('reports.filters.search_item'),
            Filter::customer(),
            Filter::warehouse(),
            Filter::toggle('expiring', 'reports.stock_on_hand.expiring'),
        ];
    }

    public function columns(): array
    {
        return [
            'item' => ['label' => 'reports.columns.item', 'card' => 'title'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'batch' => ['label' => 'reports.columns.batch'],
            'expiry' => ['label' => 'reports.columns.expiry_date'],
            'expiry_status' => ['label' => 'reports.columns.expiry_status'],
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
            'reserved' => ['label' => 'reports.columns.reserved', 'align' => 'end'],
            'available' => ['label' => 'reports.columns.available', 'align' => 'end'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $search = trim($filters['search']);

        return StockBalance::query()
            ->join('product as p', 'p.id', '=', 'stock_balance.product_id')
            ->join('customer as c', 'c.id', '=', 'stock_balance.customer_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_balance.warehouse_id')
            ->whereIn('stock_balance.warehouse_id', $this->warehouseIds($filters, $user))
            ->where('stock_balance.qty_base', '>', 0)
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_balance.customer_id', $filters['customer']))
            // An item's name or code, or a batch number.
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereIn('stock_balance.product_id', Product::query()->search($search)->select('product.id'))
                ->orWhereRaw('upper(stock_balance.batch_no) like ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtoupper($search)).'%'])))
            ->when($filters['expiring'] === '1', fn ($query) => $query
                ->where('stock_balance.expiry_date', '<=', $this->today()->addDays(StockOnHandReport::EXPIRING_DAYS)->toDateString()))
            ->select([
                'stock_balance.warehouse_id',
                'stock_balance.customer_id',
                'stock_balance.product_id',
                'stock_balance.expiry_date',
                'stock_balance.batch_no',
                'stock_balance.qty_base as qty',
                'stock_balance.qty_reserved as reserved',
                'c.name as customer_name',
                'w.name as warehouse_name',
            ])
            ->orderBy('p.name_en')
            ->orderBy('c.name')
            ->orderBy('w.name')
            ->orderByRaw('stock_balance.expiry_date asc nulls last')
            ->orderBy('stock_balance.batch_no');
    }

    /**
     * Totals of every row the filters match (all pages), for the table footer
     * (same shape as StockOnHandReport::totals()).
     *
     * @param  array<string, string>  $filters
     * @return array{rows: int, qty: float, reserved: float, available: float, units: list<string>}
     */
    public function totals(array $filters, User $user): array
    {
        $totals = DB::query()
            ->fromSub($this->query($filters, $user)->reorder()->toBase(), 's')
            ->join('product as tp', 'tp.id', '=', 's.product_id')
            ->join('unit as tu', 'tu.id', '=', 'tp.base_unit_id')
            ->selectRaw('count(*) as row_count, coalesce(sum(s.qty), 0) as qty, coalesce(sum(s.reserved), 0) as reserved')
            ->selectRaw("coalesce(string_agg(distinct tu.code, '|'), '') as units")
            ->first();

        return [
            'rows' => (int) $totals->row_count,
            'qty' => (float) $totals->qty,
            'reserved' => (float) $totals->reserved,
            'available' => (float) $totals->qty - (float) $totals->reserved,
            'units' => $totals->units === '' ? [] : explode('|', $totals->units),
        ];
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $batch = match (true) {
            $row->batch_no !== null => (string) $row->batch_no,
            $row->expiry_date !== null => __('stock.batch.none'),
            default => '',
        };

        return [
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'customer' => (string) $row->customer_name,
            'warehouse' => (string) $row->warehouse_name,
            'batch' => ['text' => $batch, 'ltr' => $row->batch_no !== null],
            'expiry' => $this->expiryDateCell($row->expiry_date),
            'expiry_status' => $this->expiryCell($row->expiry_date, StockOnHandReport::EXPIRING_DAYS),
            'qty' => $this->qtyCell($row->qty, $row->product_id) + ['strong' => true],
            'reserved' => (float) $row->reserved > 0 ? $this->qtyCell($row->reserved, $row->product_id) : ['text' => '', 'value' => 0],
            'available' => $this->qtyCell((float) $row->qty - (float) $row->reserved, $row->product_id) + ['strong' => true],
        ];
    }

    public function link(object $row): ?string
    {
        // The item's stock page needs the Stock permission.
        return Gate::allows('stock.view')
            ? route('stock.item', ['product' => $row->product_id, 'warehouse' => $row->warehouse_id])
            : null;
    }
}
