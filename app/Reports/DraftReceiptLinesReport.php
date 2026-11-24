<?php

namespace App\Reports;

/**
 * Draft receipt details: every line of every Draft receipt, with the Receipt
 * confirming it created.
 */
final class DraftReceiptLinesReport extends DraftLinesReport
{
    public function key(): string
    {
        return 'draft-receipt-lines';
    }

    protected function type(): string
    {
        return 'IN';
    }

    protected function createdLabel(): string
    {
        return 'reports.columns.receipt';
    }
}
