<?php

namespace App\Policies;

use App\Models\User;

/**
 * Managing accounts. Rules that keep an administrator from locking themselves
 * out (deactivating their own account, removing their own ADMIN role) are
 * validation errors in the Users screen, so they are explained inline.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.users.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admin.users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission('admin.users.manage');
    }
}
