<?php

namespace App\Reports;

/**
 * Opening balance details: every line of every Opening balance, quantities as
 * entered only.
 */
final class OpeningBalanceLinesReport extends OperationLinesReport
{
    public function key(): string
    {
        return 'opening-balance-lines';
    }

    public function icon(): string
    {
        return 'account_balance_wallet';
    }

    public function group(): string
    {
        return 'operations';
    }

    protected function type(): string
    {
        return 'OPENING_BALANCE';
    }

    protected function hasBranch(): bool
    {
        return false;
    }

    protected function showsStatus(): bool
    {
        return false;
    }

    protected function showsBaseQuantity(): bool
    {
        return false;
    }
}
