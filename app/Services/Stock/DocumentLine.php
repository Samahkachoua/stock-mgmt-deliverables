<?php

namespace App\Services\Stock;

/**
 * One line of a document to post: what the user entered, before conversion.
 * $expiry (Y-m-d) is the expiry date received, or, on stock going out or a
 * count, the expiry date chosen. On an Adjustment, $qty is the counted
 * quantity (0 allowed) and $serials the serial numbers found.
 */
final class DocumentLine
{
    /**
     * @param  string  $unit  unit code (e.g. BOX)
     * @param  list<string>  $serials  serial numbers, for serial-numbered items
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $unit,
        public readonly string|float|int $qty,
        public readonly ?string $expiry = null,
        public readonly array $serials = [],
    ) {}
}
