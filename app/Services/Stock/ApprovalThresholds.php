<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * The approval limit of an item for a transaction type, in base units, from
 * fn_resolve_threshold (item → customer → global). Null when none is
 * configured: then nothing needs approval.
 *
 * A line needs approval when its base quantity is at or above the limit, so a
 * limit of 0 sends everything for approval.
 */
final class ApprovalThresholds
{
    /** @var array<string, float|null> per-request memo */
    private array $resolved = [];

    public function resolve(string $productId, string $customerId, string $type): ?float
    {
        $key = $productId.'|'.$customerId.'|'.$type;

        if (! array_key_exists($key, $this->resolved)) {
            $value = DB::selectOne('SELECT fn_resolve_threshold(?, ?, ?) AS qty', [$productId, $customerId, $type])->qty;
            $this->resolved[$key] = $value === null ? null : (float) $value;
        }

        return $this->resolved[$key];
    }

    public function needsApproval(float $qtyBase, ?float $threshold): bool
    {
        return $threshold !== null && $qtyBase > 0 && $qtyBase >= $threshold - 1e-9;
    }
}
