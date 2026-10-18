<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits customer-owned rows to the signed-in customer user's own customer
 * (app_user.is_customer_user + customer_id). Staff users are not filtered here;
 * every stock query still accepts an explicit customer filter.
 *
 * Without a signed-in user (console commands, queued jobs) nothing is filtered.
 */
class CustomerScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_customer_user) {
            return;
        }

        // Only models using CustomerScoped register this scope.
        $column = method_exists($model, 'customerColumn') ? $model->customerColumn() : 'customer_id';

        if ($user->customer_id === null) {
            // A customer login without a customer sees nothing rather than everything.
            $builder->whereRaw('false');

            return;
        }

        $builder->where($model->qualifyColumn($column), $user->customer_id);
    }
}
