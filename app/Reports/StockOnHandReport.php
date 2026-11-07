<?php

namespace App\Reports;

use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock on hand (the Stock page, stock.view): what each customer has in each of
 * the user's warehouses, per item, with the nearest expiry. From stock_balance
 * (current state). Stock on its way in a transfer is not shown here (see the
 * Transfers in transit report).
 *
 * Reserved is the part held for Orders / Delivery notes not delivered yet;
 * Available is what is left for anything else (in stock - reserved).
 */
final class StockOnHandReport extends Report
{
    /** "Expiring" means within this many days, expired included. */
    public const EXPIRING_DAYS = 30;

    public function key(): string
    {
        return 'stock-on-hand';
    }

    public function permission(): string
    {
        return 'stock.view';
    }

    public function icon(): string
    {
        return 'inventory_2';
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
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
            'reserved' => ['label' => 'reports.columns.reserved', 'align' => 'end'],
            'available' => ['label' => 'reports.columns.available', 'align' => 'end'],
            'expiry' => ['label' => 'reports.columns.nearest_expiry'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        // On hand per warehouse, customer and item (expiry dates added up), with the nearest expiry.
        $onHand = StockBalance::query()
            ->whereIn('stock_balance.warehouse_id', $this->warehouseIds($filters, $user))
            ->where('stock_balance.qty_base', '>', 0)
            ->groupBy('stock_balance.warehouse_id', 'stock_balance.customer_id', 'stock_balance.product_id')
            ->select(['stock_balance.warehouse_id', 'stock_balance.customer_id', 'stock_balance.product_id'])
            ->selectRaw('sum(stock_balance.qty_base) as qty, sum(stock_balance.qty_reserved) as reserved, min(stock_balance.expiry_date) as nearest_expiry');

        $search = mb_strtolower($filters['search']);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return DB::query()
            ->fromSub($onHand, 'h')
            ->join('product as p', 'p.id', '=', 'h.product_id')
            ->join('customer as c', 'c.id', '=', 'h.customer_id')
            ->join('warehouse as w', 'w.id', '=', 'h.warehouse_id')
            ->when($filters['customer'] !== '', fn ($query) => $query->where('h.customer_id', $filters['customer']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(p.sku) like ?', [$like])
                ->orWhereRaw('lower(p.name_en) like ?', [$like])
                ->orWhereRaw('lower(p.name_ar) like ?', [$like])
                ->orWhereRaw('lower(p.name_fr) like ?', [$like])))
            ->when($filters['expiring'] === '1', fn ($query) => $query
                ->where('h.nearest_expiry', '<=', $this->today()->addDays(self::EXPIRING_DAYS)->toDateString()))
            ->select([
                'h.warehouse_id',
                'h.customer_id',
                'h.product_id',
                'h.qty',
                'h.reserved',
                'h.nearest_expiry',
                'c.name as customer_name',
                'w.name as warehouse_name',
            ])
            ->orderBy('p.name_en')
            ->orderBy('c.name')
            ->orderBy('w.name');
    }

    /**
     * Totals of every row the filters match (all pages), for the table footer.
     *
     * @param  array<string, string>  $filters
     * @return array{rows: int, qty: float, reserved: float, available: float, units: list<string>}
     */
    public function totals(array $filters, User $user): array
    {
        $totals = DB::query()
            ->fromSub($this->query($filters, $user)->reorder(), 's')
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

        return [
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'customer' => (string) $row->customer_name,
            'warehouse' => (string) $row->warehouse_name,
            'qty' => $this->qtyCell($row->qty, $row->product_id) + ['strong' => true],
            'reserved' => (float) $row->reserved > 0 ? $this->qtyCell($row->reserved, $row->product_id) : ['text' => '', 'value' => 0],
            'available' => $this->qtyCell((float) $row->qty - (float) $row->reserved, $row->product_id) + ['strong' => true],
            'expiry' => $this->expiryCell($row->nearest_expiry, self::EXPIRING_DAYS),
        ];
    }

    public function link(object $row): string
    {
        return route('stock.item', ['product' => $row->product_id, 'warehouse' => $row->warehouse_id]);
    }
}
