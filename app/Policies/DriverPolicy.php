<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

/**
 * Settings › Drivers: each action has its own permission (driver.view /
 * create / edit / delete). Choosing a driver when dispatching needs none of
 * these, only the Delivery note's own permission.
 */
class DriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('driver.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('driver.create');
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->hasPermission('driver.edit');
    }

    public function delete(User $user, Driver $driver): bool
    {
        return $user->hasPermission('driver.delete');
    }
}
