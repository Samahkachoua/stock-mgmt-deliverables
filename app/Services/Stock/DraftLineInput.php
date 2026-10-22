<?php

namespace App\Services\Stock;

/**
 * One line of a draft: like a DocumentLine, in its own warehouse.
 */
final class DraftLineInput
{
    /**
     * @param  string  $unit  unit code (e.g. BOX)
     * @param  string|null  $expiry  Y-m-d: received (receipt) or chosen (issue), expiry items only
     * @param  list<string>  $serials  serial numbers, for serial-numbered items
     */
    public function __construct(
        public readonly string $warehouseId,
        public readonly string $productId,
        public readonly string $unit,
        public readonly string|int $qty,
        public readonly ?string $expiry = null,
        public readonly array $serials = [],
    ) {}
}
