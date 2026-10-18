<?php

namespace App\Services\Stock;

use Carbon\CarbonImmutable;

/**
 * A stock document to post (App\Services\Stock\PostingService::post()).
 */
final class Document
{
    /**
     * @param  string  $type  transaction_type.code
     * @param  array<string, DocumentLine>  $lines  keyed by the form's row key, so problems point at the row
     * @param  string|null  $toWarehouseId  destination of a transfer
     * @param  string|null  $reason  reason code, for types that ask for one (Damage, Adjustment)
     * @param  string|null  $requestId  UUID of the form that submits it (stock_transaction.client_request_id);
     *                                  a document with the same one is saved only once
     */
    public function __construct(
        public readonly string $type,
        public readonly string $warehouseId,
        public readonly string $customerId,
        public readonly CarbonImmutable $date,
        public readonly ?string $note,
        public readonly array $lines,
        public readonly ?string $toWarehouseId = null,
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
    ) {}
}
