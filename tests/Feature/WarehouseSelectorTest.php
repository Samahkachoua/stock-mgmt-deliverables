<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\WarehouseSwitcher;
use App\Models\Warehouse;
use Livewire\Livewire;

it('lists only the warehouses assigned to the user', function () {
    $beirut = Warehouse::factory()->create(['name' => 'Beirut Main']);
    $tripoli = Warehouse::factory()->create(['name' => 'Tripoli']);
    Warehouse::factory()->create(['name' => 'Saida']);
    $closed = Warehouse::factory()->create(['name' => 'Zahle', 'active' => false]);

    $user = userWithWarehouses($beirut, $tripoli, $closed);

    Livewire::actingAs($user)
        ->test(WarehouseSwitcher::class)
        ->assertSee('Beirut Main')
        ->assertSee('Tripoli')
        ->assertDontSee('Saida')
        ->assertDontSee('Zahle');
});

it('starts in the first assigned warehouse', function () {
    $user = userWithWarehouses(
        Warehouse::factory()->create(['name' => 'Tripoli']),
        $beirut = Warehouse::factory()->create(['name' => 'Beirut Main']),
    );

    $this->actingAs($user)
        ->get(route('receive'))
        ->assertOk()
        ->assertSessionHas(EnsureCurrentWarehouse::SESSION_KEY, $beirut->id);
});

it('switches to another assigned warehouse', function () {
    $beirut = Warehouse::factory()->create();
    $tripoli = Warehouse::factory()->create();
    $user = userWithWarehouses($beirut, $tripoli);

    Livewire::actingAs($user)
        ->test(WarehouseSwitcher::class)
        ->call('select', $tripoli->id)
        ->assertRedirect();

    expect(session(EnsureCurrentWarehouse::SESSION_KEY))->toBe($tripoli->id);
});

it('refuses a warehouse the user is not assigned to', function (?string $id) {
    $user = userWithWarehouses();
    $other = Warehouse::factory()->create();

    Livewire::actingAs($user)
        ->test(WarehouseSwitcher::class)
        ->call('select', $id ?? $other->id)
        ->assertForbidden();
})->with([
    'another warehouse' => null,
    'not a uuid' => 'not-a-uuid',
]);

it('ignores a stale warehouse in the session', function () {
    $assigned = Warehouse::factory()->create();
    $other = Warehouse::factory()->create();
    $user = userWithWarehouses($assigned);

    $this->actingAs($user)
        ->withSession([EnsureCurrentWarehouse::SESSION_KEY => $other->id])
        ->get(route('receive'))
        ->assertOk()
        ->assertSessionHas(EnsureCurrentWarehouse::SESSION_KEY, $assigned->id);
});

it('explains when the user has no warehouse', function () {
    $user = userWithWarehouses();
    $user->warehouses()->detach();

    $this->actingAs($user)
        ->get(route('receive'))
        ->assertForbidden()
        ->assertSee(__('app.no_warehouses_title'));
});
