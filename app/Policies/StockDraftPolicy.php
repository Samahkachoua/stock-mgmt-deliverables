<?php

namespace App\Policies;

use App\Models\StockDraft;
use App\Models\User;
use App\Services\Stock\DraftService;
use App\Support\Drafts\DraftTypes;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/**
 * What the draft pages offer. DraftService and PostingService::confirmDraft
 * check the same rules again: the buttons are only UI.
 */
class StockDraftPolicy
{
    /**
     * Change a draft (Edit): still a draft, and the type's create or confirm permission.
     */
    public function update(User $user, StockDraft $draft): bool
    {
        $kind = DraftTypes::get($draft->transaction_type);

        return $draft->isDraft()
            && ($user->hasPermission($kind->createPermission()) || $user->hasPermission($kind->confirmPermission()))
            && DraftService::canSee($this->header($draft), $user);
    }

    /**
     * Confirm a draft: still a draft, the type's confirm permission and every
     * warehouse of its lines. Its creator may confirm it too.
     */
    public function confirm(User $user, StockDraft $draft): Response
    {
        $kind = DraftTypes::get($draft->transaction_type);

        if (! $draft->isDraft() || ! $user->hasPermission($kind->confirmPermission()) || ! DraftService::canSee($this->header($draft), $user)) {
            return Response::deny(__('drafts.errors.cannot_confirm'));
        }

        foreach ($draft->warehouseIds() as $warehouseId) {
            if (! $user->canAccessWarehouse($warehouseId)) {
                return Response::deny(__('drafts.errors.warehouse_not_allowed', [
                    'warehouse' => (string) DB::table('warehouse')->where('id', $warehouseId)->value('name'),
                ]));
            }
        }

        return Response::allow();
    }

    /**
     * Cancel a draft: still a draft, and the type's create permission.
     */
    public function cancel(User $user, StockDraft $draft): bool
    {
        return $draft->isDraft()
            && $user->hasPermission(DraftTypes::get($draft->transaction_type)->createPermission())
            && DraftService::canSee($this->header($draft), $user);
    }

    private function header(StockDraft $draft): object
    {
        return (object) $draft->only(['id', 'customer_id', 'customer_branch_id', 'created_by']);
    }
}
