<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lines of completed documents of one type over a period (by document date),
 * with their reason: the Damage and Adjustments reports. Pending, rejected and
 * cancelled documents changed no stock, so they are left out.
 */
abstract class PostedLinesReport extends Report
{
    /** transaction_type.code */
    abstract protected function type(): string;

    /**
     * @return list<Filter>
     */
    public function filters(): array
    {
        $reasons = ['' => 'reports.filters.all_reasons'];

        foreach (TransactionTypes::get($this->type())->reasons() as $reason) {
            $reasons[$reason] = 'transactions.reasons.'.$reason;
        }

        return [
            Filter::search('reports.filters.search_item'),
            ...Filter::dates(),
            Filter::select('reason', 'reports.filters.reason', $reasons),
            Filter::warehouse(),
            Filter::customer(),
        ];
    }

    /**
     * The lines, joined with what every row shows.
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockTransaction>
     */
    protected function lines(array $filters, User $user): Builder
    {
        return StockTransaction::query()
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('customer as c', 'c.id', '=', 'stock_transaction.customer_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_transaction.warehouse_id')
            ->join('app_user as u', 'u.id', '=', 'stock_transaction.created_by')
            // Who approved it, when it needed approval.
            ->leftJoin('approval as a', fn ($join) => $join->on('a.transaction_id', '=', 'stock_transaction.id')->where('a.decision', '=', 'APPROVED'))
            ->leftJoin('app_user as ap', 'ap.id', '=', 'a.decided_by')
            ->where('stock_transaction.transaction_type', $this->type())
            ->where('stock_transaction.status', 'POSTED')
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds($filters, $user))
            // The document's own date (the Date column).
            ->tap(fn ($query) => $this->dates($filters)->whereDay($query, 'stock_transaction.document_date'))
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'l.product_id'))
            ->when($filters['reason'] !== '', fn ($query) => $query->where('stock_transaction.reason_code', $filters['reason']))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->select([
                'stock_transaction.doc_no', 'stock_transaction.document_date', 'stock_transaction.reason_code',
                'l.product_id', 'l.qty_base', 'l.qty_system', 'l.qty_counted', 'l.direction',
                'l.expiry_date', 'c.name as customer_name', 'w.name as warehouse_name', 'u.full_name as created_by_name', 'ap.full_name as approved_by_name',
            ])
            ->orderByDesc('stock_transaction.document_date')
            ->orderByDesc('stock_transaction.doc_no')
            ->orderBy('l.line_no');
    }

    /**
     * Cells every line report starts with.
     *
     * @return array<string, mixed>
     */
    protected function commonCells(object $row): array
    {
        $product = $this->product($row->product_id);

        return [
            'date' => Format::date(CarbonImmutable::parse($row->document_date)),
            'reference' => $this->referenceCell($row->doc_no),
            'warehouse' => (string) $row->warehouse_name,
            'customer' => (string) $row->customer_name,
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
        ];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink($this->type(), $row->doc_no);
    }
}
