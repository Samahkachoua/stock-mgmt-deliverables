<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Demo data for local development. Never runs in production.
 * One account per role, all with the password "password":
 * admin@, manager@, operator@ (Beirut Main only), dataentry@, auditor@ and
 * customer@ (Acme Foods' portal user) — all @example.test.
 */
class LocalSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LocalSeeder must never run in production.');
        }

        $warehouses = collect([
            ['code' => 'BEY', 'name' => 'Beirut Main', 'city' => 'Beirut'],
            ['code' => 'TRP', 'name' => 'Tripoli', 'city' => 'Tripoli'],
            ['code' => 'SAI', 'name' => 'Saida', 'city' => 'Saida'],
            ['code' => 'ZAH', 'name' => 'Zahle', 'city' => 'Zahle'],
        ])->map(fn (array $warehouse) => Warehouse::updateOrCreate(['code' => $warehouse['code']], $warehouse));

        $acme = Customer::updateOrCreate(['code' => 'ACME'], ['name' => 'Acme Foods']);
        Customer::updateOrCreate(['code' => 'CEDAR'], ['name' => 'Cedar Trading']);

        $beirut = $warehouses->where('code', 'BEY');

        // Roles are seeded by schema.sql; their permissions by the permissions migration.
        $this->account('admin@example.test', 'Admin', 'ADMIN', $warehouses);
        $this->account('manager@example.test', 'Maya Manager', 'MANAGER', $warehouses);
        $this->account('operator@example.test', 'Omar Operator', 'OPERATOR', $beirut);
        $this->account('dataentry@example.test', 'Dina Data Entry', 'DATA_ENTRY', $warehouses);
        $this->account('auditor@example.test', 'Adam Auditor', 'AUDITOR', $warehouses);

        $customer = $this->account('customer@example.test', 'Acme Portal', 'CUSTOMER', $warehouses);
        $customer->forceFill(['is_customer_user' => true, 'customer_id' => $acme->id])->save();

        $this->call(ItemSeeder::class);
        $this->call(StockSeeder::class);
    }

    /**
     * @param  Collection<int, Warehouse>  $warehouses
     */
    private function account(string $email, string $name, string $role, Collection $warehouses): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['full_name' => $name, 'password_hash' => 'password', 'active' => true],
        );

        $user->roles()->sync(Role::where('code', $role)->firstOrFail());
        $user->warehouses()->sync($warehouses->pluck('id'));

        return $user;
    }
}
