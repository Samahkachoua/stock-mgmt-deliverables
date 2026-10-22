<?php

namespace App\Reports;

use App\Models\StockBalance;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Stock on hand (the Stock page, stock.view): what each customer has in each of
 * the user's warehouses, per item, with what is on its way there and the
 * nearest expiry. From stock_balance (current state) and in_transit_balance
 * (transfers dispatched to the warehouse, not yet received).
 *
 * In transit is stock arriving at the warehouse: it is available in neither
 * warehouse until it is received, so it is shown apart from the quantity.
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
            'breakdown' => ['label' => 'reports.columns.qty_larger', 'priority' => 2, 'card' => 'hide'],
            'in_transit' => ['label' => 'reports.columns.in_transit', 'align' => 'end'],
            'expiry' => ['label' => 'reports.columns.nearest_expiry'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $warehouses = $this->warehouseIds($filters, $user);

        // On hand per warehouse, customer and item (expiry dates added up), with the nearest expiry.
        $onHand = StockBalance::query()
            ->whereIn('stock_balance.warehouse_id', $warehouses)
            ->where('stock_balance.qty_base', '>', 0)
            ->groupBy('stock_balance.warehouse_id', 'stock_balance.customer_id', 'stock_balance.product_id')
            ->select(['stock_balance.warehouse_id', 'stock_balance.customer_id', 'stock_balance.product_id'])
            ->selectRaw('sum(stock_balance.qty_base) as qty, min(stock_balance.expiry_date) as nearest_expiry');

        // Arriving: dispatched to these warehouses, not yet received.
        $arriving = StockTransaction::query()
            ->join('in_transit_balance as i', 'i.transaction_id', '=', 'stock_transaction.id')
            ->where('stock_transaction.status', 'DISPATCHED')
            ->whereIn('stock_transaction.to_warehouse_id', $warehouses)
            ->groupBy('stock_transaction.to_warehouse_id', 'stock_transaction.customer_id', 'i.product_id')
            ->selectRaw('stock_transaction.to_warehouse_id as warehouse_id, stock_transaction.customer_id, i.product_id, sum(i.qty_base) as qty');

        $key = fn (string $column) => DB::raw("coalesce(h.$column, t.$column)");
        $as = fn (string $column) => DB::raw("coalesce(h.$column, t.$column) as $column");
        $search = mb_strtolower($filters['search']);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return DB::query()
            ->fromSub($onHand, 'h')
            ->joinSub($arriving, 't', fn (JoinClause $join) => $join
                ->on('t.warehouse_id', '=', 'h.warehouse_id')
                ->on('t.customer_id', '=', 'h.customer_id')
                ->on('t.product_id', '=', 'h.product_id'), type: 'full')
            ->join('product as p', 'p.id', '=', $key('product_id'))
            ->join('customer as c', 'c.id', '=', $key('customer_id'))
            ->join('warehouse as w', 'w.id', '=', $key('warehouse_id'))
            ->when($filters['customer'] !== '', fn ($query) => $query->where($key('customer_id'), $filters['customer']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(p.sku) like ?', [$like])
                ->orWhereRaw('lower(p.name_en) like ?', [$like])
                ->orWhereRaw('lower(p.name_ar) like ?', [$like])
                ->orWhereRaw('lower(p.name_fr) like ?', [$like])))
            ->when($filters['expiring'] === '1', fn ($query) => $query
                ->where('h.nearest_expiry', '<=', $this->today()->addDays(self::EXPIRING_DAYS)->toDateString()))
            ->select([
                $as('warehouse_id'),
                $as('customer_id'),
                $as('product_id'),
                DB::raw('coalesce(h.qty, 0) as qty'),
                DB::raw('coalesce(t.qty, 0) as in_transit'),
                'h.nearest_expiry',
                'c.name as customer_name',
                'w.name as warehouse_name',
            ])
            ->orderBy('p.name_en')
            ->orderBy('c.name')
            ->orderBy('w.name');
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
            'breakdown' => (float) $row->qty > 0 ? $this->breakdown($row->qty, $row->product_id) : '',
            'in_transit' => (float) $row->in_transit > 0 ? $this->qtyCell($row->in_transit, $row->product_id) : ['text' => '', 'value' => 0],
            'expiry' => $this->expiryCell($row->nearest_expiry, self::EXPIRING_DAYS),
        ];
    }

    public function link(object $row): string
    {
        return route('stock.item', ['product' => $row->product_id, 'warehouse' => $row->warehouse_id]);
    }
}
