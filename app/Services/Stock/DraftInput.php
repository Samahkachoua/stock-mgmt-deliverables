<?php

namespace App\Services\Stock;

use Carbon\CarbonImmutable;

/**
 * A draft receipt / issue as the form submits it (App\Services\Stock\DraftService).
 */
final class DraftInput
{
    /**
     * @param  string  $type  transaction_type.code confirming creates (IN / OUT)
     * @param  array<string, DraftLineInput>  $lines  keyed by the form's row key, so problems point at the row
     * @param  string|null  $requestId  UUID of the form that creates it (stock_draft.client_request_id)
     * @param  string|null  $version  stock_draft.updated_at as the form opened it (edits only):
     *                                a draft changed since is not overwritten
     */
    public function __construct(
        public readonly string $type,
        public readonly string $customerId,
        public readonly string $branchId,
        public readonly CarbonImmutable $date,
        public readonly ?string $note,
        public readonly array $lines,
        public readonly ?string $requestId = null,
        public readonly ?string $version = null,
    ) {}
}
