<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('product.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('product.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('product.manage');
    }

    /**
     * Whether nothing uses the item is checked by the item form (it says why not).
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('product.manage');
    }
}
