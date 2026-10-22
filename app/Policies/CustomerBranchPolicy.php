<?php

namespace App\Policies;

use App\Models\CustomerBranch;
use App\Models\User;

class CustomerBranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function update(User $user, CustomerBranch $branch): bool
    {
        return $user->hasPermission('masterdata.manage');
    }
}
