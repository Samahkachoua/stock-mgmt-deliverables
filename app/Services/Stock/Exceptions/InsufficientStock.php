<?php

namespace App\Services\Stock\Exceptions;

/**
 * Taking stock out would make a balance negative (CLAUDE.md rule 2):
 * "Only 64 Piece of Canned Beans 400g available in Beirut Main". Carries the
 * quantity that is available, in base units, so the form and the API can say so.
 */
class InsufficientStock extends StockException
{
    /**
     * @param  array<string, string|int|float>  $params
     */
    public function __construct(
        string $key,
        array $params,
        public readonly float $available,
        public readonly float $requested,
        public readonly float $expired = 0,
        ?string $row = null,
    ) {
        parent::__construct($key, $params, $row, 'qty');
    }
}
