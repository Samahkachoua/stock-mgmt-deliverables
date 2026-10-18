<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->hasPermission('masterdata.manage');
    }
}
