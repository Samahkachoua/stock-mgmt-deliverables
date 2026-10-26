<?php

namespace App\Services\Stock;

/**
 * One line of a draft: like a DocumentLine (the draft's warehouse is in DraftInput).
 */
final class DraftLineInput
{
    /**
     * @param  string  $unit  unit code (e.g. BOX)
     * @param  string|null  $expiry  Y-m-d: received (receipt) or chosen (order), expiry items only
     * @param  list<string>  $serials  serial numbers, for serial-numbered items
     * @param  string|null  $batch  batch number of an expiry item: typed coming in; going out the
     *                              batch picked ('' = stock without a batch), null = the only batch of that date
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $unit,
        public readonly string|int $qty,
        public readonly ?string $expiry = null,
        public readonly array $serials = [],
        public readonly ?string $batch = null,
    ) {}
}
