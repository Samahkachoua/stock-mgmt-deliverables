<?php

namespace App\Services\Stock;

/**
 * What was delivered for one line of a Delivery note (PostingService::deliver()).
 */
final class DeliveredLine
{
    /**
     * @param  string|float|int  $qty  delivered, in the unit the line was ordered in (0 allowed)
     * @param  string|null  $reason  why less was delivered: PostingService::DELIVERY_REASONS
     * @param  list<string>  $serials  serial numbers delivered, for serial-numbered items
     * @param  DocumentLine|null  $instead  another item delivered instead (reason REPLACED)
     */
    public function __construct(
        public readonly string|float|int $qty,
        public readonly ?string $reason = null,
        public readonly array $serials = [],
        public readonly ?DocumentLine $instead = null,
    ) {}
}
