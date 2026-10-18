<?php

use App\Livewire\Settings\Customers;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('adds a customer and records it', function () {
    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Customers::class)
        ->call('add')
        ->set('form.name', 'Levant Distributors')
        ->set('form.code', 'lev')
        ->set('form.contact_email', 'orders@levant.test')
        ->set('form.contact_phone', '+961 1 234 567')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Levant Distributors');

    $customer = Customer::where('code', 'LEV')->firstOrFail();

    expect($customer)->contact_email->toBe('orders@levant.test')->active->toBeTrue()
        ->and(DB::table('audit_log')->where('entity', 'customer')->where('entity_id', $customer->id)->value('action'))->toBe('CREATE');
});

it('edits and deactivates a customer', function () {
    $customer = Customer::factory()->create(['name' => 'Acme']);

    Livewire::actingAs(userWithRole('MANAGER'))
        ->test(Customers::class)
        ->call('edit', $customer->id)
        ->set('form.contact_phone', '')
        ->set('form.active', false)
        ->call('save')
        // Deactivating asks first.
        ->assertSet('confirm.action', 'deactivate')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect($customer->fresh())->active->toBeFalse()->contact_phone->toBeNull();
});

it('checks code, email and phone', function (string $field, string $value, string $rule) {
    Customer::factory()->create(['code' => 'ACME']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Customers::class)
        ->call('add')
        ->set('form.'.$field, $value)
        ->assertHasErrors(['form.'.$field => $rule]);
})->with([
    'taken code' => ['code', 'acme', 'unique'],
    'bad email' => ['contact_email', 'not-an-email', 'email'],
    'bad phone' => ['contact_phone', 'call me', 'regex'],
]);

it('is only for users who manage master data', function () {
    Livewire::actingAs(userWithRole('AUDITOR'))
        ->test(Customers::class)
        ->assertForbidden();
});
