<?php

namespace App\Reports;

use App\Models\StockBalance;
use App\Models\User;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dormant stock: items still in stock whose last movement (in or out) in that
 * warehouse is more than N days old, longest idle first. Stock that takes up
 * space without moving is worth raising with its customer. Quantities from
 * stock_balance (all expiry dates together), the last movement from stock_movement.
 */
final class DormantStockReport extends Report
{
    public function key(): string
    {
        return 'dormant-stock';
    }

    public function icon(): string
    {
        return 'history';
    }

    public function filters(): array
    {
        return [
            Filter::search('reports.filters.search_item'),
            Filter::warehouse(),
            Filter::customer(),
            Filter::number('days', 'reports.dormant_stock.days', 90, 1, 730),
        ];
    }

    public function columns(): array
    {
        return [
            'item' => ['label' => 'reports.columns.item', 'card' => 'title'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer'],
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
            'breakdown' => ['label' => 'reports.columns.qty_larger', 'priority' => 2, 'card' => 'hide'],
            'last_moved' => ['label' => 'reports.columns.last_moved'],
            'days_idle' => ['label' => 'reports.columns.days_idle', 'align' => 'end', 'card' => 'badge'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $warehouses = $this->warehouseIds($filters, $user);
        $cutoff = CarbonImmutable::now()->subDays((int) $filters['days']);

        // When each item last moved in each warehouse, for each customer.
        $lastMoved = DB::table('stock_movement')
            ->whereIn('warehouse_id', $warehouses)
            ->groupBy('warehouse_id', 'customer_id', 'product_id')
            ->select(['warehouse_id', 'customer_id', 'product_id'])
            ->selectRaw('max(created_at) as last_moved_at');

        return StockBalance::query()
            ->joinSub($lastMoved, 'lm', fn ($join) => $join->on('lm.warehouse_id', '=', 'stock_balance.warehouse_id')
                ->on('lm.customer_id', '=', 'stock_balance.customer_id')
                ->on('lm.product_id', '=', 'stock_balance.product_id'))
            ->join('customer as c', 'c.id', '=', 'stock_balance.customer_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_balance.warehouse_id')
            ->join('product as p', 'p.id', '=', 'stock_balance.product_id')
            ->whereIn('stock_balance.warehouse_id', $warehouses)
            ->where('lm.last_moved_at', '<', $cutoff)
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'stock_balance.product_id'))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_balance.customer_id', $filters['customer']))
            ->groupBy('stock_balance.warehouse_id', 'stock_balance.customer_id', 'stock_balance.product_id', 'lm.last_moved_at', 'c.name', 'w.name', 'p.name_en')
            ->havingRaw('sum(stock_balance.qty_base) > 0')
            ->select(['stock_balance.product_id', 'stock_balance.warehouse_id', 'lm.last_moved_at', 'c.name as customer_name', 'w.name as warehouse_name'])
            ->selectRaw('sum(stock_balance.qty_base) as qty_base')
            ->orderBy('lm.last_moved_at')
            ->orderBy('p.name_en')
            ->orderBy('w.name');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $moved = CarbonImmutable::parse($row->last_moved_at)->setTimezone(CompanyTime::zone());
        $idle = (int) $moved->startOfDay()->diffInDays(CompanyTime::today());

        return [
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'customer' => (string) $row->customer_name,
            'warehouse' => (string) $row->warehouse_name,
            'qty' => $this->qtyCell($row->qty_base, $row->product_id) + ['strong' => true],
            'breakdown' => $this->breakdown($row->qty_base, $row->product_id),
            'last_moved' => Format::date($moved),
            'days_idle' => ['text' => __('reports.dormant_stock.idle', ['days' => Format::qty($idle)]), 'value' => $idle, 'tone' => 'pending'],
        ];
    }

    /**
     * The item's stock in that warehouse (as on the Stock page).
     */
    public function link(object $row): string
    {
        return route('stock.item', ['product' => $row->product_id, 'warehouse' => $row->warehouse_id]);
    }
}
