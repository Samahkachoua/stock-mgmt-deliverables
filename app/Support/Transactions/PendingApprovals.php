<?php

namespace App\Support\Transactions;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\StockTransaction;
use App\Models\User;

/**
 * Documents waiting for this user's approval in the current warehouse, per
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
        $warehouseId = session(EnsureCurrentWarehouse::SESSION_KEY);
        $types = collect(TransactionTypes::all())
            ->filter(fn (TypeUi $type) => $type->approvePermission() !== null && $user->hasPermission($type->approvePermission()));

        if ($warehouseId === null || $types->isEmpty()) {
            return [];
        }

        return StockTransaction::query()
            ->where('warehouse_id', $warehouseId)
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
