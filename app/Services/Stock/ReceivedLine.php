<?php

namespace App\Services\Stock;

/**
 * What arrived for one line of a transfer (PostingService::receive()).
 */
final class ReceivedLine
{
    /**
     * @param  string|float|int  $qty  in the unit the line was sent in
     * @param  string|null  $reason  why less arrived: DAMAGED_IN_TRANSIT, MISSING, COUNTING_ERROR
     * @param  list<string>  $serials  serial numbers that arrived, for serial-numbered items
     */
    public function __construct(
        public readonly string|float|int $qty,
        public readonly ?string $reason = null,
        public readonly array $serials = [],
    ) {}
}
