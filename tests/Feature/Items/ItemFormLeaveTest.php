<?php

use App\Livewire\Items\ItemForm;
use Livewire\Livewire;
use Tests\Support\Catalog;

/*
 * Leaving the item form (Cancel, the back link, any other link) asks first
 * when something was changed (App\Livewire\Concerns\WarnsBeforeLeaving).
 */

beforeEach(function () {
    $this->catalog = Catalog::create();
    $this->product = $this->catalog->items['CB-400'];
    $this->edit = fn () => Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(ItemForm::class, ['product' => $this->product]);
});

it('leaves at once when nothing was changed', function () {
    ($this->edit)()
        ->assertSet('unsaved', false)
        ->call('leave', route('items'))
        ->assertSet('confirm', null)
        ->assertRedirect(route('items'));
});

it('asks before throwing away changes, and leaves only when the user discards them', function () {
    $form = ($this->edit)()->set('nameEn', 'Canned Beans 410g')->assertSet('unsaved', true);

    $form->call('leave', route('items'))->assertNoRedirect();

    expect($form->get('confirm'))->toMatchArray([
        'action' => 'leave',
        'title' => __('confirm.discard.item.title', ['name' => $this->product->name()]),
        'body' => __('confirm.discard.item.body'),
        'button' => __('confirm.discard.item.button'),
    ]);

    // "Keep it": the changes stay, nothing is saved.
    $form->call('dismissConfirm')->assertSet('confirm', null)->assertSet('nameEn', 'Canned Beans 410g')->assertNoRedirect();

    // "Discard changes": off to where the user was going, nothing saved.
    $form->call('leave', route('items'))->call('runConfirmed')->assertRedirect(route('items'));
    expect($this->product->fresh()->name_en)->toBe('Canned Beans 400g');
});

it('counts every part of the form as a change', function (string $property, mixed $value) {
    $value = $value === '(other customer)' ? $this->catalog->cedar->id : $value;

    ($this->edit)()->set($property, $value)->assertSet('unsaved', true);
})->with([
    'customer' => ['customerId', '(other customer)'],
    'code' => ['sku', 'CB-401'],
    'arabic name' => ['nameAr', 'فول'],
    'approval limit' => ['threshold', '24'],
    'tracking' => ['trackExpiry', true],
    'active' => ['active', false],
]);

it('counts a changed unit row, but not the empty row or a change typed and undone', function () {
    $form = ($this->edit)();
    $box = collect($form->get('units'))->search(fn ($row) => $row['factor'] === '12');

    $form->set("units.$box.factor", '24')->assertSet('unsaved', true)
        ->set("units.$box.factor", '12')->assertSet('unsaved', false)
        ->set('nameEn', 'Something else')->set('nameEn', 'Canned Beans 400g')->assertSet('unsaved', false)
        ->call('leave', route('items'))->assertRedirect(route('items'));
});

it('asks on a new item once anything is typed', function () {
    $form = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(ItemForm::class)->assertSet('unsaved', false);

    $form->set('nameEn', 'Chickpeas')->call('leave', route('items'))->assertNoRedirect();

    expect($form->get('confirm.title'))->toBe(__('confirm.discard.item.title_new'));
});

it('never asks after saving', function () {
    ($this->edit)()->set('nameEn', 'Canned Beans 410g')->call('save')
        ->assertHasNoErrors()
        ->assertSet('unsaved', false)
        ->assertRedirect(route('items'));
});

it('only goes to pages of this app', function (string $to, string $expected) {
    ($this->edit)()->set('nameEn', 'Changed')->call('leave', $to)->call('runConfirmed')->assertRedirect($expected);
})->with([
    'a page here' => [fn () => url('/items?tab=x'), '/items?tab=x'],
    'another site' => ['https://evil.example/items', '/'],
    'protocol-relative' => ['//evil.example/items', '/'],
]);
