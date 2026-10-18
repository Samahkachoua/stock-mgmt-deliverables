<?php

namespace App\Services\Stock\Exceptions;

/**
 * An adjustment waiting for approval no longer matches the stock: the balance
 * (or a serial) changed after it was counted. It must be rejected and counted
 * again.
 */
final class StaleCount extends StockException
{
    /**
     * @param  array<string, string|int|float>  $params
     */
    public function __construct(string $key, array $params = [], ?string $row = null)
    {
        parent::__construct($key, $params, $row);
    }
}
