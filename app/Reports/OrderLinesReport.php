<?php

namespace App\Reports;

use App\Support\Format;
use Carbon\CarbonImmutable;

/**
 * Order details: every line of every Order, with its delivery date and the
 * Delivery note confirming it created. Quantities as entered only.
 */
final class OrderLinesReport extends DraftLinesReport
{
    public function key(): string
    {
        return 'order-lines';
    }

    protected function type(): string
    {
        return 'OUT';
    }

    protected function createdLabel(): string
    {
        return 'reports.columns.delivery_note';
    }

    protected function showsBaseQuantity(): bool
    {
        return false;
    }

    protected function extraColumns(): array
    {
        return [
            'delivery_date' => ['label' => 'reports.columns.delivery_date', 'priority' => 2],
        ];
    }

    protected function extraCells(object $row): array
    {
        return [
            'delivery_date' => $row->delivery_date === null ? '' : ['text' => Format::date(CarbonImmutable::parse($row->delivery_date)), 'nowrap' => true],
        ];
    }
}
