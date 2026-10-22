<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CustomerScope;

/**
 * Use on every model whose rows belong to a customer, so a customer user
 * (a user with a customer_id) only ever sees their own customer's rows. Bypass it only
 * deliberately, with withoutGlobalScope(CustomerScope::class).
 *
 * Override customerColumn() when the customer is not in `customer_id`
 * (e.g. `id` on the customer table itself).
 */
trait CustomerScoped
{
    public static function bootCustomerScoped(): void
    {
        static::addGlobalScope(new CustomerScope);
    }

    public function customerColumn(): string
    {
        return 'customer_id';
    }
}
