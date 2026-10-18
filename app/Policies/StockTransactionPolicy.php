<?php

namespace App\Policies;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Auth\Access\Response;

class StockTransactionPolicy
{
    /**
     * Approve or reject a document waiting for approval: the type's approve
     * permission, in one of the user's warehouses, and never one's own document
     * (the database refuses that too: approval.chk_approver_not_requester).
     */
    public function decide(User $user, StockTransaction $transaction): Response
    {
        $permission = TransactionTypes::get($transaction->transaction_type)->approvePermission();

        if ($permission === null || ! $user->hasPermission($permission)) {
            return Response::deny(__('stock.errors.cannot_approve'));
        }

        if ($transaction->status !== 'PENDING_APPROVAL') {
            return Response::deny(__('stock.errors.not_pending'));
        }

        if ($transaction->created_by === $user->id) {
            return Response::deny(__('stock.errors.own_document'));
        }

        if (! $user->canAccessWarehouse($transaction->warehouse_id)) {
            return Response::deny(__('stock.errors.warehouse_not_allowed'));
        }

        return Response::allow();
    }
}
