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
 * Transfer shortages: transfer lines that arrived with less than was sent, with
 * the reason given at receipt, by the day they were received. Shown to users
 * of the source or the destination.
 */
final class TransferShortagesReport extends Report
{
    public function key(): string
    {
        return 'transfer-shortages';
    }

    public function filters(): array
    {
        $reasons = ['' => 'reports.filters.all_reasons'];

        foreach (PostingService::SHORTAGE_REASONS as $reason) {
            $reasons[$reason] = 'transfers.reasons.'.$reason;
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
            'received_on' => ['label' => 'transfers.shortages.columns.received_on'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'from' => ['label' => 'reports.columns.from', 'priority' => 2],
            'to' => ['label' => 'reports.columns.to', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            'sent' => ['label' => 'transfers.shortages.columns.sent', 'align' => 'end'],
            'received' => ['label' => 'transfers.shortages.columns.received', 'align' => 'end'],
            'short' => ['label' => 'transfers.shortages.columns.short', 'align' => 'end', 'card' => 'badge'],
            'reason' => ['label' => 'transfers.shortages.columns.reason', 'priority' => 2],
            'received_by' => ['label' => 'transfers.shortages.columns.received_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $warehouses = $this->warehouseIds($filters, $user);
        $timezone = $this->today()->getTimezone();

        return StockTransaction::query()
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('warehouse as wf', 'wf.id', '=', 'stock_transaction.warehouse_id')
            ->join('warehouse as wt', 'wt.id', '=', 'stock_transaction.to_warehouse_id')
            ->join('customer as c', 'c.id', '=', 'stock_transaction.customer_id')
            ->leftJoin('app_user as r', 'r.id', '=', 'stock_transaction.received_by')
            ->where('stock_transaction.transaction_type', 'TRANSFER')
            ->whereNotNull('l.shortage_reason')
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'l.product_id'))
            ->where(fn ($query) => $query->whereIn('stock_transaction.warehouse_id', $warehouses)->orWhereIn('stock_transaction.to_warehouse_id', $warehouses))
            ->tap(fn ($query) => $this->dates($filters)->whereInstant($query, 'stock_transaction.received_at', $timezone))
            ->when($filters['reason'] !== '', fn ($query) => $query->where('l.shortage_reason', $filters['reason']))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->select([
                'stock_transaction.doc_no', 'stock_transaction.received_at',
                'l.product_id', 'l.qty_base', 'l.qty_received', 'l.shortage_reason',
                'wf.name as from_name', 'wt.name as to_name', 'c.name as customer_name', 'r.full_name as received_by_name',
            ])
            ->orderByDesc('stock_transaction.received_at')
            ->orderBy('l.line_no');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $short = (float) $row->qty_base - (float) $row->qty_received;

        return [
            'received_on' => Format::date(CarbonImmutable::parse($row->received_at)->setTimezone(CompanyTime::zone())),
            'reference' => $this->referenceCell($row->doc_no),
            'from' => (string) $row->from_name,
            'to' => (string) $row->to_name,
            'customer' => (string) $row->customer_name,
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'sent' => $this->qtyCell($row->qty_base, $row->product_id),
            'received' => $this->qtyCell($row->qty_received, $row->product_id),
            'short' => $this->qtyCell($short, $row->product_id) + ['tone' => 'rejected'],
            'reason' => __('transfers.reasons.'.$row->shortage_reason),
            'received_by' => (string) $row->received_by_name,
        ];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink('TRANSFER', $row->doc_no);
    }
}
