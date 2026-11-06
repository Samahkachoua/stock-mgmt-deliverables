<?php

use App\Livewire\Settings\Drivers;
use App\Models\Driver;
use App\Models\Warehouse;
use App\Services\Stock\PostingService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

it('adds a driver and records it', function () {
    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Drivers::class)
        ->call('add')
        ->set('form.name', ' Karim Haddad ')
        ->set('form.licence_no', 'lb-12345')
        ->set('form.national_id', 'ab 998877')
        ->set('form.phone', '+961 3 123 456')
        ->set('form.truck_no', 'tr-77')
        ->call('save')
        ->assertHasNoErrors();

    $driver = Driver::where('licence_no', 'LB-12345')->firstOrFail();

    expect($driver->name)->toBe('Karim Haddad')
        ->and($driver->national_id)->toBe('AB 998877')
        ->and($driver->truck_no)->toBe('TR-77')
        ->and(DB::table('audit_log')->where('entity', 'driver')->where('entity_id', $driver->id)->value('action'))->toBe('CREATE');
});

it('edits a driver', function () {
    $driver = driver();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Drivers::class)
        ->call('edit', $driver->id)
        ->assertSet('form.name', 'Karim')
        ->set('form.truck_no', 'NEW-9')
        ->call('save')
        ->assertHasNoErrors();

    expect($driver->fresh()->truck_no)->toBe('NEW-9');
});

it('requires every field and refuses a taken licence or national ID', function () {
    $other = driver();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Drivers::class)
        ->call('add')
        ->set('form.licence_no', strtolower($other->licence_no))
        ->set('form.national_id', $other->national_id)
        ->set('form.phone', 'call me')
        ->call('save')
        ->assertHasErrors([
            'form.name' => 'required',
            'form.licence_no' => 'unique',
            'form.national_id' => 'unique',
            'form.phone' => 'regex',
            'form.truck_no' => 'required',
        ]);

    expect(Driver::count())->toBe(1);
});

it('deletes a driver no delivery note uses, and records it', function () {
    $driver = driver();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Drivers::class)
        ->call('askDelete', $driver->id)
        ->assertSet('confirm.action', 'delete')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect(Driver::find($driver->id))->toBeNull()
        ->and(DB::table('audit_log')->where('entity', 'driver')->where('entity_id', $driver->id)->value('action'))->toBe('DELETE');
});

it('cannot delete a driver on a delivery note', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);
    $note = $stock->confirmOrder([$stock->line('BEANS', 2)]);
    $driver = driver();
    app(PostingService::class)->dispatchDelivery($note, $driver->id, $stock->user);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Drivers::class)
        ->call('askDelete', $driver->id)
        ->assertHasErrors('delete.'.$driver->id)
        // No modal: the reason is shown instead.
        ->assertSet('confirm', null)
        ->assertSee(trans_choice('confirm.blocked.driver_in_use', 1, ['name' => 'Karim', 'count' => 1]));

    expect(Driver::find($driver->id))->not->toBeNull();
});

it('lets a user who may only view see the list but change nothing', function () {
    $driver = driver();
    $user = userWithPermissions(Warehouse::factory()->create(), ['driver.view']);

    $component = Livewire::actingAs($user)->test(Drivers::class)
        ->assertOk()
        ->assertSee('Karim')
        ->assertDontSee(__('settings.drivers.add'))
        ->assertDontSee("edit('".$driver->id."')", false)
        ->assertDontSee("askDelete('".$driver->id."')", false);

    $component->call('add')->assertForbidden();
    Livewire::actingAs($user)->test(Drivers::class)->call('edit', $driver->id)->assertForbidden();
    Livewire::actingAs($user)->test(Drivers::class)->call('askDelete', $driver->id)->assertForbidden();
});

it('checks each action against its own permission', function (string $permission, string $action) {
    $driver = driver();
    $user = userWithPermissions(Warehouse::factory()->create(), ['driver.view', $permission]);

    $component = Livewire::actingAs($user)->test(Drivers::class);
    $action === 'add' ? $component->call('add')->assertOk() : $component->call($action, $driver->id)->assertOk();
})->with([
    ['driver.create', 'add'],
    ['driver.edit', 'edit'],
    ['driver.delete', 'askDelete'],
]);

it('is only for users who may see drivers', function () {
    Livewire::actingAs(userWithRole('OPERATOR'))
        ->test(Drivers::class)
        ->assertForbidden();
});
