<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery note details: every line of every Delivery note, with its Order,
 * what was delivered (and why less), the driver and when it was delivered.
 */
final class DeliveryNoteLinesReport extends OperationLinesReport
{
    public function key(): string
    {
        return 'delivery-note-lines';
    }

    protected function type(): string
    {
        return 'OUT';
    }

    /** Quantities as entered only (Delivered is in the base unit). */
    protected function showsBaseQuantity(): bool
    {
        return false;
    }

    protected function extraColumns(): array
    {
        return [
            'delivered' => ['label' => 'reports.columns.delivered', 'align' => 'end'],
            'reason' => ['label' => 'reports.columns.reason', 'priority' => 2],
            'order' => ['label' => 'reports.columns.order', 'priority' => 2],
            'driver' => ['label' => 'reports.columns.driver', 'priority' => 2, 'card' => 'hide'],
            'truck' => ['label' => 'reports.columns.truck', 'priority' => 2, 'card' => 'hide'],
            'delivered_at' => ['label' => 'reports.columns.delivered_at', 'priority' => 2],
        ];
    }

    protected function extraCells(object $row): array
    {
        return [
            // Empty until it is delivered.
            'delivered' => $row->qty_received === null ? '' : $this->qtyCell($row->qty_received, $row->product_id),
            'reason' => $row->shortage_reason === null ? '' : __('deliveries.reasons.'.$row->shortage_reason),
            'order' => ['text' => (string) $row->order_reference, 'ltr' => true],
            'driver' => (string) $row->driver_name,
            'truck' => ['text' => (string) $row->truck_no, 'ltr' => true],
            'delivered_at' => $row->delivered_at === null ? '' : ['text' => Format::dateTime(CarbonImmutable::parse($row->delivered_at)->setTimezone(CompanyTime::zone())), 'nowrap' => true],
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
            ->addSelect([
                'l.qty_received', 'l.shortage_reason', 'd.doc_no as order_reference',
                'stock_transaction.driver_name', 'stock_transaction.truck_no', 'stock_transaction.delivered_at',
            ]);
    }
}
