<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Transfers in transit: stock dispatched and not yet received, per item and
 * expiry date, oldest first, with how many days it has been on its way. From
 * in_transit_balance. Shown to users of the source or the destination.
 */
final class TransfersInTransitReport extends Report
{
    public function key(): string
    {
        return 'transfers-in-transit';
    }

    public function icon(): string
    {
        return 'local_shipping';
    }

    public function filters(): array
    {
        return [
            Filter::search('reports.filters.search_item'),
            Filter::warehouse(),
            Filter::select('direction', 'transfers.direction.label', [
                '' => 'transfers.direction.all',
                'incoming' => 'transfers.direction.incoming',
                'outgoing' => 'transfers.direction.outgoing',
            ]),
            Filter::customer(),
        ];
    }

    public function columns(): array
    {
        return [
            'dispatched' => ['label' => 'reports.columns.dispatched'],
            'days' => ['label' => 'reports.columns.days_in_transit', 'align' => 'end'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'from' => ['label' => 'reports.columns.from', 'priority' => 2],
            'to' => ['label' => 'reports.columns.to'],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $warehouses = $this->warehouseIds($filters, $user);

        return StockTransaction::query()
            ->join('in_transit_balance as i', 'i.transaction_id', '=', 'stock_transaction.id')
            ->join('warehouse as wf', 'wf.id', '=', 'stock_transaction.warehouse_id')
            ->join('warehouse as wt', 'wt.id', '=', 'stock_transaction.to_warehouse_id')
            ->join('customer as c', 'c.id', '=', 'stock_transaction.customer_id')
            ->where('stock_transaction.status', 'DISPATCHED')
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'i.product_id'))
            ->where(fn ($query) => match ($filters['direction']) {
                'incoming' => $query->whereIn('stock_transaction.to_warehouse_id', $warehouses),
                'outgoing' => $query->whereIn('stock_transaction.warehouse_id', $warehouses),
                default => $query->whereIn('stock_transaction.warehouse_id', $warehouses)->orWhereIn('stock_transaction.to_warehouse_id', $warehouses),
            })
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->select([
                'stock_transaction.doc_no', 'stock_transaction.dispatched_at',
                'i.product_id', 'i.qty_base', 'i.expiry_date',
                'wf.name as from_name', 'wf.timezone', 'wt.name as to_name', 'c.name as customer_name',
            ])
            ->orderBy('stock_transaction.dispatched_at')
            ->orderBy('stock_transaction.doc_no')
            ->orderBy('i.expiry_date');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $dispatched = CarbonImmutable::parse($row->dispatched_at)->setTimezone($row->timezone);
        $days = (int) $dispatched->startOfDay()->diffInDays(CarbonImmutable::now($row->timezone)->startOfDay());

        return [
            'dispatched' => Format::dateTime($dispatched),
            'days' => ['text' => Format::qty($days), 'value' => $days, 'tone' => $days >= 3 ? 'pending' : 'transit'],
            'reference' => $this->referenceCell($row->doc_no),
            'from' => (string) $row->from_name,
            'to' => (string) $row->to_name,
            'customer' => (string) $row->customer_name,
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'expiry_date' => ['text' => $row->expiry_date === null ? '' : Format::date(CarbonImmutable::parse($row->expiry_date))],
            'qty' => $this->qtyCell($row->qty_base, $row->product_id) + ['strong' => true],
        ];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink('TRANSFER', $row->doc_no);
    }
}
