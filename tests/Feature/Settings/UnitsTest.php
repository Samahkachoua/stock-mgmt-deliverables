<?php

use App\Livewire\Settings\Units;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Catalog;

it('adds a unit and records it', function () {
    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Units::class)
        ->call('add')
        ->set('form.name', 'Drum')
        ->set('form.code', 'drm')
        ->call('save')
        ->assertHasNoErrors();

    $unit = Unit::where('code', 'DRM')->firstOrFail();

    expect($unit->name)->toBe('Drum')
        ->and(DB::table('audit_log')->where('entity', 'unit')->where('entity_id', $unit->id)->value('action'))->toBe('CREATE');
});

it('refuses taken and badly formed codes', function (string $code, string $rule) {
    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Units::class)
        ->call('add')
        ->set('form.code', $code)
        ->assertHasErrors(['form.code' => $rule]);
})->with([
    'taken' => ['box', 'unique'],
    'spaces' => ['BIG BOX', 'regex'],
]);

it('deletes a unit nothing uses, and records it', function () {
    $unit = Unit::create(['code' => 'DRM', 'name' => 'Drum']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Units::class)
        ->call('askDelete', $unit->id)
        ->assertSet('confirm.action', 'delete')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect(Unit::find($unit->id))->toBeNull()
        ->and(DB::table('audit_log')->where('entity_id', $unit->id)->value('action'))->toBe('DELETE');
});

it('cannot delete a unit in use', function (string $code) {
    Catalog::create(); // CB-400 is counted in Piece, sold by the Box

    $unit = Unit::where('code', $code)->firstOrFail();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Units::class)
        ->call('askDelete', $unit->id)
        ->assertHasErrors('delete.'.$unit->id)
        // No modal: the reason is shown instead.
        ->assertSet('confirm', null)
        ->assertSee($unit->label().' is used by ');

    expect(Unit::find($unit->id))->not->toBeNull();
})->with([
    'as a base unit' => ['PC'],
    'as another unit' => ['BOX'],
]);

it('shows how many items use each unit', function () {
    Catalog::create();

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(Units::class)
        ->set('search', 'box')
        ->assertSee(trans_choice('settings.units.item_count', 2, ['count' => 2]));
});

it('is only for users who manage master data', function () {
    Livewire::actingAs(userWithRole('OPERATOR'))
        ->test(Units::class)
        ->assertForbidden();
});
