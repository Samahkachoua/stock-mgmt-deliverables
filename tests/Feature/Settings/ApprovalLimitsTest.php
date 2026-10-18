<?php

use App\Livewire\Settings\ApprovalLimits;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return array<string, float>
 */
function globalLimits(): array
{
    return DB::table('approval_threshold')
        ->where('scope', 'GLOBAL')
        ->whereNull('scope_id')
        ->pluck('qty_base', 'transaction_type')
        ->map(fn ($qty) => (float) $qty)
        ->all();
}

it('shows one limit per transaction type that supports approval', function () {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->assertSet('limits', ['DAMAGE' => '', 'ADJUSTMENT' => ''])
        ->assertSee(__('settings.limits.label', ['type' => __('types.damage')]))
        ->assertSee(__('settings.limits.label', ['type' => __('types.adjustment')]));
});

it('saves the global limits and updates them in place', function () {
    $component = Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->set('limits.DAMAGE', '10')
        ->set('limits.ADJUSTMENT', '2.5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', toast: ['type' => 'success', 'message' => __('settings.limits.saved'), 'url' => null, 'link' => null]);

    expect(globalLimits())->toEqual(['DAMAGE' => 10.0, 'ADJUSTMENT' => 2.5]);

    $component->set('limits.DAMAGE', '0')->call('save');

    expect(globalLimits())->toEqual(['DAMAGE' => 0.0, 'ADJUSTMENT' => 2.5])
        ->and(DB::table('approval_threshold')->count())->toBe(2);
});

it('loads the saved limits', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 12]);

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->assertSet('limits.DAMAGE', '12');
});

it('rejects negative and over-precise limits', function (string $value, string $rule) {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->set('limits.DAMAGE', $value)
        ->assertHasErrors(['limits.DAMAGE' => $rule]);
})->with([
    ['-1', 'min'],
    ['1.23456', 'decimal'],
]);

it('removes a global limit left empty: then nothing needs approval', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 12]);

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->set('limits.DAMAGE', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(globalLimits())->toBe([]);
});

it('adds, saves and removes customer limits', function () {
    $acme = Customer::factory()->create(['name' => 'Acme Foods']);
    $cedar = Customer::factory()->create(['name' => 'Cedar Trading']);

    $page = Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->assertSee(__('settings.limits.no_customers'))
        ->set('newCustomer', $acme->id)
        ->assertSet('customerLimits', [$acme->id => ['DAMAGE' => '', 'ADJUSTMENT' => '']])
        ->assertSet('newCustomer', '')
        ->set("customerLimits.{$acme->id}.DAMAGE", '5')
        ->set('newCustomer', $cedar->id)
        ->set("customerLimits.{$cedar->id}.ADJUSTMENT", '-2')
        ->assertHasErrors(["customerLimits.{$cedar->id}.ADJUSTMENT" => 'min'])
        ->set("customerLimits.{$cedar->id}.ADJUSTMENT", '0')
        ->call('save')
        ->assertHasNoErrors();

    $customerLimits = fn () => DB::table('approval_threshold')->where('scope', 'CUSTOMER')
        ->orderBy('qty_base')->get(['scope_id', 'transaction_type', 'qty_base'])
        ->map(fn ($row) => [$row->scope_id, $row->transaction_type, (float) $row->qty_base])->all();

    // Empty fields fall back to the global limit, so they store nothing.
    expect($customerLimits())->toBe([[$cedar->id, 'ADJUSTMENT', 0.0], [$acme->id, 'DAMAGE', 5.0]]);

    // Loaded again on the next visit.
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->assertSet("customerLimits.{$acme->id}.DAMAGE", '5')
        ->assertSee('Acme Foods');

    // Saved limits are deleted only after confirming.
    $page->call('removeCustomer', $acme->id)->assertSet('confirm.action', 'deleteLimits');
    expect($customerLimits())->toHaveCount(2);

    $page->call('runConfirmed')->assertSet('confirm', null);
    expect($customerLimits())->toBe([[$cedar->id, 'ADJUSTMENT', 0.0]]);
});

it('ignores a customer that does not exist', function () {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ApprovalLimits::class)
        ->set('customerLimits', ['5b8f8f0e-0000-4000-8000-000000000000' => ['DAMAGE' => '3', 'ADJUSTMENT' => '']])
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('approval_threshold')->where('scope', 'CUSTOMER')->count())->toBe(0);
});

it('is only for users who manage settings', function () {
    Livewire::actingAs(userWithRole('MANAGER'))
        ->test(ApprovalLimits::class)
        ->assertForbidden();
});
