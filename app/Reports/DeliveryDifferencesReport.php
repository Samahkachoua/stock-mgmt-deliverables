<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\PostingService;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery differences: Delivery note lines delivered with less than the Order
 * asked for (part, nothing, or another item instead), with the reason given on
 * delivery, by the day they were delivered. Answers "what did the customer not
 * get, and why?" for managers and the customer's account staff.
 */
final class DeliveryDifferencesReport extends Report
{
    public function key(): string
    {
        return 'delivery-differences';
    }

    public function filters(): array
    {
        $reasons = ['' => 'reports.filters.all_reasons'];

        foreach (PostingService::DELIVERY_REASONS as $reason) {
            $reasons[$reason] = 'deliveries.reasons.'.$reason;
        }

        return [
            Filter::search('reports.filters.search_item'),
            ...Filter::dates(),
            Filter::select('reason', 'reports.filters.reason', $reasons),
            Filter::warehouse(),
            Filter::customer(),
        ];
    }

    public function columns(): array
    {
        return [
            'delivered_on' => ['label' => 'deliveries.differences.columns.delivered_on'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'order' => ['label' => 'deliveries.differences.columns.order', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            'ordered' => ['label' => 'deliveries.differences.columns.ordered', 'align' => 'end'],
            'delivered' => ['label' => 'deliveries.differences.columns.delivered', 'align' => 'end'],
            'short' => ['label' => 'deliveries.differences.columns.short', 'align' => 'end', 'card' => 'badge'],
            'reason' => ['label' => 'deliveries.differences.columns.reason', 'priority' => 2],
            'instead' => ['label' => 'deliveries.differences.columns.instead', 'priority' => 2],
            'delivered_by' => ['label' => 'deliveries.differences.columns.delivered_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $timezone = $this->today()->getTimezone();

        return StockTransaction::query()
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('customer as c', 'c.id', '=', 'stock_transaction.customer_id')
            ->leftJoin('stock_draft as d', 'd.id', '=', 'stock_transaction.draft_id')
            ->leftJoin('app_user as u', 'u.id', '=', 'stock_transaction.delivered_by')
            // The item delivered instead, if any (a line pointing at this one).
            ->leftJoin('stock_transaction_line as i', 'i.replaces_line_id', '=', 'l.id')
            ->where('stock_transaction.transaction_type', 'OUT')
            ->where('stock_transaction.status', 'POSTED')
            ->whereNotNull('l.shortage_reason')
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds($filters, $user))
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'l.product_id'))
            ->tap(fn ($query) => $this->dates($filters)->whereInstant($query, 'stock_transaction.delivered_at', $timezone))
            ->when($filters['reason'] !== '', fn ($query) => $query->where('l.shortage_reason', $filters['reason']))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->select([
                'stock_transaction.doc_no', 'stock_transaction.delivered_at',
                'l.product_id', 'l.qty_base', 'l.qty_received', 'l.shortage_reason',
                'i.product_id as instead_product_id', 'i.qty_base as instead_qty',
                'd.doc_no as order_reference', 'c.name as customer_name', 'u.full_name as delivered_by_name',
            ])
            ->orderByDesc('stock_transaction.delivered_at')
            ->orderBy('l.line_no');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $short = (float) $row->qty_base - (float) $row->qty_received;
        $instead = $row->instead_product_id === null ? null : $this->product($row->instead_product_id);

        return [
            'delivered_on' => $row->delivered_at === null ? '' : Format::date(CarbonImmutable::parse($row->delivered_at)->setTimezone(CompanyTime::zone())),
            'reference' => $this->referenceCell($row->doc_no),
            'order' => ['text' => (string) $row->order_reference, 'ltr' => true],
            'customer' => (string) $row->customer_name,
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'ordered' => $this->qtyCell($row->qty_base, $row->product_id),
            'delivered' => $this->qtyCell($row->qty_received, $row->product_id),
            'short' => $this->qtyCell($short, $row->product_id) + ['tone' => 'rejected'],
            'reason' => __('deliveries.reasons.'.$row->shortage_reason),
            'instead' => $instead === null ? '' : $instead->name().' · '.Format::qtyUnit((float) $row->instead_qty, $instead->converter()->base()),
            'delivered_by' => (string) $row->delivered_by_name,
        ];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink('OUT', $row->doc_no);
    }
}
