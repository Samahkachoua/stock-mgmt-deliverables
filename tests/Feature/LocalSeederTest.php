<?php

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\LocalSeeder;

it('seeds one user per role, with the operator in Beirut Main only', function () {
    $this->seed(LocalSeeder::class);

    $users = User::with(['roles', 'warehouses'])->get()->keyBy('email');

    foreach ([
        'admin@example.test' => 'ADMIN',
        'manager@example.test' => 'MANAGER',
        'operator@example.test' => 'OPERATOR',
        'dataentry@example.test' => 'DATA_ENTRY',
        'auditor@example.test' => 'AUDITOR',
        'customer@example.test' => 'CUSTOMER',
    ] as $email => $role) {
        expect($users[$email]->roles->pluck('code')->all())->toBe([$role]);
    }

    expect($users['operator@example.test']->warehouses->pluck('code')->all())->toBe(['BEY'])
        ->and($users['admin@example.test']->warehouses)->toHaveCount(4)
        ->and($users['customer@example.test']->isCustomerUser())->toBeTrue()
        ->and($users['customer@example.test']->customer_id)->toBe(Customer::where('code', 'ACME')->value('id'));
});

it('can run twice', function () {
    $this->seed(LocalSeeder::class);
    $this->seed(LocalSeeder::class);

    expect(User::count())->toBe(6);
});
