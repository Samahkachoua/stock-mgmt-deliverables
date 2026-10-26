<?php

namespace App\Services\Stock\Exceptions;

/**
 * A batch number already known for the item with another expiry date: a batch
 * has one expiry date ("Batch B-1042 of Olive Oil 1L expires 12 Mar 2027").
 * Shown under the row's expiry date.
 */
final class BatchDateMismatch extends StockException
{
    /**
     * @param  array<string, string|int|float>  $params  batch, item, date
     */
    public function __construct(array $params, ?string $row = null)
    {
        parent::__construct('stock.errors.batch_other_date', $params, $row, 'expiry');
    }
}
