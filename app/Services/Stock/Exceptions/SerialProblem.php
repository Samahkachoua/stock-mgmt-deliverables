<?php

namespace App\Services\Stock\Exceptions;

/**
 * A serial number is duplicated, already in stock, or not in stock here.
 */
final class SerialProblem extends StockException
{
    /**
     * @param  array<string, string|int|float>  $params
     */
    public function __construct(string $key, array $params, public readonly string $serial, ?string $row = null)
    {
        parent::__construct($key, $params, $row, 'serials');
    }
}
