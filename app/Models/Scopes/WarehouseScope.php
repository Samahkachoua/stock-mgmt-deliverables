<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits warehouse-bound rows to the signed-in user's warehouses (user_warehouse).
 * A row is visible when any of its warehouse columns is one of them, so a
 * transfer shows up in both the source and the destination warehouse.
 *
 * Without a signed-in user (console commands, queued jobs) nothing is filtered:
 * that code must scope its queries explicitly.
 */
class WarehouseScope implements Scope
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

        // Only models using WarehouseScoped register this scope.
        $columns = method_exists($model, 'warehouseColumns') ? $model->warehouseColumns() : ['warehouse_id'];
        $warehouseIds = $user->warehouseIds();

        $builder->where(function (Builder $query) use ($model, $columns, $warehouseIds) {
            foreach ($columns as $column) {
                $query->orWhereIn($model->qualifyColumn($column), $warehouseIds);
            }
        });
    }
}
