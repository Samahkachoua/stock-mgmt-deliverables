<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('masterdata.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermission('masterdata.manage');
    }
}
