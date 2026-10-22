<?php

namespace App\Support\Transactions;

use App\Models\StockTransaction;
use App\Models\User;

/**
 * Documents waiting for this user's approval in any of their warehouses, per
 * type slug, for the sidebar badges: types the user may approve, not created
 * by the user (they can't approve their own).
 */
final class PendingApprovals
{
    /**
     * @return array<string, int> type slug => count (only types with something waiting)
     */
    public static function bySlug(User $user): array
    {
        $types = collect(TransactionTypes::all())
            ->filter(fn (TypeUi $type) => $type->approvePermission() !== null && $user->hasPermission($type->approvePermission()));

        if ($types->isEmpty()) {
            return [];
        }

        return StockTransaction::query()
            ->whereIn('warehouse_id', $user->warehouseIds())
            ->where('status', 'PENDING_APPROVAL')
            ->where('created_by', '<>', $user->id)
            ->whereIn('transaction_type', $types->keys())
            ->groupBy('transaction_type')
            ->selectRaw('transaction_type, count(*) as pending')
            ->pluck('pending', 'transaction_type')
            ->mapWithKeys(fn ($count, string $code) => [$types[$code]->slug() => (int) $count])
            ->all();
    }
}
