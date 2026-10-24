<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Which drafts the signed-in user sees (on top of CustomerScope):
 *
 * - a customer user: their customer's drafts (CustomerScope), only of their
 *   branches when they are linked to some;
 * - staff: drafts for one of their warehouses (user_warehouse), and their own.
 *
 * Without a signed-in user (console commands, queued jobs) nothing is filtered.
 */
class DraftScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if ($user->isCustomerUser()) {
            if ($user->branchIds() !== []) {
                $builder->whereIn($model->qualifyColumn('customer_branch_id'), $user->branchIds());
            }

            return;
        }

        $builder->where(fn (Builder $query) => $query
            ->where($model->qualifyColumn('created_by'), $user->id)
            ->orWhereIn($model->qualifyColumn('warehouse_id'), $user->warehouseIds()));
    }
}
