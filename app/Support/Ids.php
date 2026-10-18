<?php

namespace App\Support;

/**
 * Record ids are BIGINT identity values (database/sql/schema.sql), handled as
 * strings of digits. Values from the browser (filters, pickers, switchers) are
 * checked with valid() before they reach a query.
 */
final class Ids
{
    /** Largest BIGINT: anything longer can't be an id. */
    private const MAX = '9223372036854775807';

    public static function valid(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value) || ! preg_match('/^[1-9]\d{0,18}$/', $value)) {
            return false;
        }

        return strlen($value) < strlen(self::MAX) || strcmp($value, self::MAX) <= 0;
    }
}
