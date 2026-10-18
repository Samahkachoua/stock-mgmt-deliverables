<?php

use App\Livewire\Settings\Warehouses;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Catalog;

it('adds a warehouse and records it', function () {
    $user = userWithRole('DATA_ENTRY');

    Livewire::actingAs($user)
        ->test(Warehouses::class)
        ->call('add')
        ->set('form.name', 'Jounieh Depot')
        ->set('form.code', ' jou ')
        ->set('form.city', 'Jounieh')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', null)
        ->assertSee('Jounieh Depot');

    $warehouse = Warehouse::where('code', 'JOU')->firstOrFail();

    expect($warehouse)
        ->name->toBe('Jounieh Depot')
        ->address->toBeNull()
        ->active->toBeTrue()
        ->and(DB::table('audit_log')->where('entity', 'warehouse')->where('entity_id', $warehouse->id)->value('action'))->toBe('CREATE');
});

it('edits a warehouse and can take it out of use while it is empty', function () {
    $warehouse = Warehouse::factory()->create(['name' => 'Old name']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Warehouses::class)
        ->call('edit', $warehouse->id)
        ->assertSet('form.name', 'Old name')
        ->set('form.name', 'New name')
        ->set('form.active', false)
        ->call('save')
        // Deactivating asks first.
        ->assertSet('confirm.action', 'deactivate')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect($warehouse->fresh())->name->toBe('New name')->active->toBeFalse();

    $audit = DB::table('audit_log')->where('entity_id', $warehouse->id)->first();
    expect(json_decode($audit->before_data, true))->toMatchArray(['name' => 'Old name', 'active' => true])
        ->and(json_decode($audit->after_data, true))->toMatchArray(['name' => 'New name', 'active' => false]);
});

it('cannot take a warehouse with stock out of use', function () {
    $catalog = Catalog::create();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Warehouses::class)
        ->call('edit', $catalog->warehouse->id)
        ->set('form.active', false)
        ->call('save')
        ->assertHasErrors('form.active')
        ->assertSee(__('settings.warehouses.errors.has_stock'));

    expect($catalog->warehouse->fresh()->active)->toBeTrue();
});

it('explains missing fields and taken codes', function () {
    Warehouse::factory()->create(['code' => 'BEY']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Warehouses::class)
        ->call('add')
        ->set('form.code', 'bey')
        ->assertHasErrors(['form.code' => 'unique'])
        ->assertSee(__('settings.warehouses.errors.code_taken'))
        ->call('save')
        ->assertHasErrors(['form.name' => 'required', 'form.city' => 'required']);

    expect(Warehouse::where('code', 'BEY')->count())->toBe(1);
});

it('keeps its own code when editing', function () {
    $warehouse = Warehouse::factory()->create(['code' => 'TRP']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Warehouses::class)
        ->call('edit', $warehouse->id)
        ->call('save')
        ->assertHasNoErrors();
});

it('searches by name, code or city', function () {
    Warehouse::factory()->create(['name' => 'Tripoli Depot', 'code' => 'TRP', 'city' => 'Tripoli']);
    Warehouse::factory()->create(['name' => 'Zahle Store', 'code' => 'ZAH', 'city' => 'Zahle']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Warehouses::class)
        ->set('search', 'trp')
        ->assertSee('Tripoli Depot')
        ->assertDontSee('Zahle Store');
});

it('is only for users who manage master data', function () {
    Livewire::actingAs(userWithRole('OPERATOR'))
        ->test(Warehouses::class)
        ->assertForbidden();
});

it('refuses to save once the permission is taken away', function () {
    $warehouse = Warehouse::factory()->create(['name' => 'Kept']);
    $user = userWithRole('DATA_ENTRY');

    $component = Livewire::actingAs($user)
        ->test(Warehouses::class)
        ->call('edit', $warehouse->id)
        ->set('form.name', 'Changed');

    $user->roles()->detach();
    Livewire::actingAs($user->fresh());

    $component->call('save')->assertForbidden();

    expect($warehouse->fresh()->name)->toBe('Kept');
});
