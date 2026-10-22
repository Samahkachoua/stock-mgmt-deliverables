<?php

namespace App\Services\Stock\Exceptions;

/**
 * Two lines of a document have the same key (CLAUDE.md rule 12: item + unit,
 * plus expiry date for expiry-tracked items; a count: item + expiry date).
 * $row is the later line, $otherRow the line it repeats; both are shown in
 * red. The message names the item and the earlier row's number (`row`), which
 * a form may replace with its own numbering.
 */
final class DuplicateLine extends StockException
{
    /**
     * @param  array<string, string|int|float>  $params
     */
    public function __construct(string $key, array $params, string $row, public readonly string $otherRow)
    {
        parent::__construct($key, $params, $row, 'item');
    }
}
