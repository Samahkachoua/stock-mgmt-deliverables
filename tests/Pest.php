<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tests that need real commits across several connections: no wrapping
// transaction. They create and delete their own rows.
pest()->extend(TestCase::class)
    ->in('Concurrency');

// Speed checks on the large dataset (stock_perf); no wrapping transaction.
pest()->extend(TestCase::class)
    ->in('Performance');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * An active OPERATOR assigned to the given warehouses (one new warehouse if none given).
 */
function userWithWarehouses(Warehouse ...$warehouses): User
{
    return userWithRole('OPERATOR', ...$warehouses);
}

/**
 * An active user with the given system role (seeded by schema.sql), assigned
 * to the given warehouses (one new warehouse if none given).
 */
function userWithRole(string $role, Warehouse ...$warehouses): User
{
    $user = User::factory()->create();

    $user->roles()->attach(Role::where('code', $role)->firstOrFail());
    $user->warehouses()->attach(collect($warehouses ?: [Warehouse::factory()->create()])->pluck('id'));

    return $user;
}
