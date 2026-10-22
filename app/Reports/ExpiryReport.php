<?php

namespace App\Reports;

use App\Models\StockBalance;
use App\Models\User;
use App\Support\CompanyTime;
use App\Support\Days;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expiry: stock that has expired, or expires within N days, per expiry date,
 * nearest first. From stock_balance (current state).
 */
final class ExpiryReport extends Report
{
    public function key(): string
    {
        return 'expiry';
    }

    public function icon(): string
    {
        return 'lock_clock';
    }

    public function filters(): array
    {
        return [
            Filter::search('reports.filters.search_item'),
            Filter::warehouse(),
            Filter::customer(),
            Filter::select('status', 'reports.filters.status', [
                'all' => 'reports.expiry.status_all',
                'expired' => 'reports.expiry.status_expired',
                'expiring' => 'reports.expiry.status_expiring',
            ]),
            Filter::number('days', 'reports.expiry.days', 30, 1, 365),
        ];
    }

    public function columns(): array
    {
        return [
            'item' => ['label' => 'reports.columns.item', 'card' => 'title'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'expiry_date' => ['label' => 'reports.columns.expiry_date'],
            'days_left' => ['label' => 'reports.columns.days_left', 'align' => 'end'],
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
            'breakdown' => ['label' => 'reports.columns.qty_larger', 'priority' => 2, 'card' => 'hide'],
            'status' => ['label' => 'reports.columns.status', 'card' => 'badge'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $today = $this->today()->toDateString();
        $until = $this->today()->addDays((int) $filters['days'])->toDateString();

        return StockBalance::query()
            ->whereNotNull('stock_balance.expiry_date')
            ->join('customer as c', 'c.id', '=', 'stock_balance.customer_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_balance.warehouse_id')
            ->join('product as p', 'p.id', '=', 'stock_balance.product_id')
            ->whereIn('stock_balance.warehouse_id', $this->warehouseIds($filters, $user))
            ->where('stock_balance.qty_base', '>', 0)
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'stock_balance.product_id'))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_balance.customer_id', $filters['customer']))
            ->when($filters['status'] === 'expired', fn ($query) => $query->where('stock_balance.expiry_date', '<', $today))
            ->when($filters['status'] === 'expiring', fn ($query) => $query->where('stock_balance.expiry_date', '>=', $today)->where('stock_balance.expiry_date', '<=', $until))
            ->when($filters['status'] === 'all', fn ($query) => $query->where('stock_balance.expiry_date', '<=', $until))
            ->select([
                'stock_balance.product_id', 'stock_balance.qty_base',
                'stock_balance.expiry_date', 'c.name as customer_name', 'w.name as warehouse_name',
            ])
            ->orderBy('stock_balance.expiry_date')
            ->orderBy('p.name_en')
            ->orderBy('w.name');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $left = Days::until($row->expiry_date, CompanyTime::zone());

        return [
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'customer' => (string) $row->customer_name,
            'warehouse' => (string) $row->warehouse_name,
            'expiry_date' => Format::date(CarbonImmutable::parse($row->expiry_date)),
            'days_left' => ['text' => Format::qty($left), 'value' => $left, 'tone' => $left < 0 ? 'rejected' : 'pending'],
            'qty' => $this->qtyCell($row->qty_base, $row->product_id) + ['strong' => true],
            'breakdown' => $this->breakdown($row->qty_base, $row->product_id),
            'status' => $this->expiryCell($row->expiry_date, (int) $filters['days']),
        ];
    }
}
