<?php

namespace App\Services\Stock\Exceptions;

use RuntimeException;

/**
 * A reason a stock document can't be saved, received, decided on or cancelled
 * (CLAUDE.md "Feedback messages"). Each carries the translation key and
 * parameters of its message; getMessage() is that message in the user's
 * language, ready for a toast and for the field it concerns.
 *
 * $row is the form's row key and $field the field to show it under
 * (item, unit, qty, expiry, serials); both null for the whole document.
 */
abstract class StockException extends RuntimeException
{
    /**
     * @param  string  $key  translation key, e.g. "stock.errors.not_enough"
     * @param  array<string, string|int|float>  $params  values for the message's placeholders
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?string $row = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct(__($key, $params));
    }
}
