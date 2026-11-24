<?php

namespace App\Reports;

use App\Models\StockTransaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Receipt details: every line of every Receipt, with the Draft receipt it was
 * confirmed from.
 */
final class ReceiptLinesReport extends OperationLinesReport
{
    public function key(): string
    {
        return 'receipt-lines';
    }

    protected function type(): string
    {
        return 'IN';
    }

    protected function extraColumns(): array
    {
        return [
            'draft' => ['label' => 'reports.columns.draft_receipt', 'priority' => 2],
        ];
    }

    protected function extraCells(object $row): array
    {
        return [
            'draft' => ['text' => (string) $row->draft_reference, 'ltr' => true],
        ];
    }

    /**
     * @param  Builder<StockTransaction>  $query
     * @return Builder<StockTransaction>
     */
    protected function extend(Builder $query): Builder
    {
        return $query
            ->leftJoin('stock_draft as d', 'd.id', '=', 'stock_transaction.draft_id')
            ->addSelect('d.doc_no as draft_reference');
    }
}
