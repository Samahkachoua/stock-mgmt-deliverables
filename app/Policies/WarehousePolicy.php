<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->hasPermission('masterdata.manage');
    }
}
