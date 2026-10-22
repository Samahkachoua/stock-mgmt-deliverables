<?php

use App\Livewire\Settings\Users;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * There is no warehouse selector in the top bar: pages that need a warehouse
 * have their own field or filter, starting on the user's default warehouse
 * (app_user.default_warehouse_id, set in Settings › Users).
 */

beforeEach(function () {
    $this->stock = Stock::create();   // Beirut Main
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->stock->user->warehouses()->attach($this->tripoli->id);
});

it('starts on the default warehouse, or the first assigned one by name', function () {
    $user = $this->stock->user->fresh();

    expect($user->defaultWarehouseId())->toBe($this->stock->warehouse->id);

    startIn($user, $this->tripoli);
    expect($user->fresh()->defaultWarehouseId())->toBe($this->tripoli->id);

    // A default that is no longer assigned (or no longer active) falls back to the first.
    $user->warehouses()->detach($this->tripoli->id);
    expect($user->fresh()->defaultWarehouseId())->toBe($this->stock->warehouse->id);

    $user->warehouses()->attach($this->tripoli->id);
    $this->tripoli->update(['active' => false]);
    expect($user->fresh()->defaultWarehouseId())->toBe($this->stock->warehouse->id);
});

it('has no warehouse selector in the top bar', function () {
    $this->actingAs($this->stock->user)
        ->get(route('receive'))
        ->assertOk()
        ->assertDontSee('wire:change="select', false)
        ->assertDontSee('warehouse-switcher', false);
});

it('saves a document in the warehouse chosen on the form, starting on the default', function () {
    startIn($this->stock->user, $this->tripoli);

    $form = Livewire::actingAs($this->stock->user->fresh())
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->assertSet('warehouseId', $this->tripoli->id)
        ->assertSee(__('transactions.form.warehouse'))
        ->call('chooseCustomer', $this->stock->customer->id)
        ->call('chooseWarehouse', $this->stock->warehouse->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id);

    [$row] = array_keys($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '5')->call('save')->assertHasNoErrors();

    expect(DB::table('stock_transaction')->value('warehouse_id'))->toBe($this->stock->warehouse->id)
        ->and($this->stock->balance('BEANS'))->toBe(5.0);
});

it('asks before changing the warehouse once lines are entered, then clears them', function () {
    $form = Livewire::actingAs($this->stock->user)
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->call('chooseCustomer', $this->stock->customer->id);

    [$row] = array_keys($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '5')
        ->call('chooseWarehouse', $this->tripoli->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->assertSet('pendingWarehouse', $this->tripoli->id)
        ->call('keepWarehouse')
        ->assertSet('pendingWarehouse', null)
        ->call('chooseWarehouse', $this->tripoli->id)
        ->call('confirmWarehouseChange')
        ->assertSet('warehouseId', $this->tripoli->id);

    expect(collect($form->get('lines'))->filter(fn (array $line) => $line['item'] !== null))->toBeEmpty();
});

it('refuses a warehouse the user does not work in, and hides the field with only one', function () {
    $elsewhere = Warehouse::factory()->create();

    Livewire::actingAs($this->stock->user)
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->call('chooseWarehouse', $elsewhere->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id);

    Livewire::actingAs(userWithRole('OPERATOR', $this->stock->warehouse))
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->assertDontSee('id="field-warehouse"', false);
});

it('lists the default warehouse first, and all the user\'s warehouses on request', function () {
    $here = $this->stock->post('IN', [$this->stock->line('BEANS', 1)]);
    $there = $this->stock->post('IN', [$this->stock->line('BEANS', 1)], warehouse: $this->tripoli);

    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->assertSet('warehouse', $this->stock->warehouse->id)
        ->assertSee($here->doc_no)->assertDontSee($there->doc_no);

    $list->set('warehouse', 'all')->assertSee($here->doc_no)->assertSee($there->doc_no);
    $list->set('warehouse', $this->tripoli->id)->assertSee($there->doc_no)->assertDontSee($here->doc_no);

    // Not one of theirs: back to the default.
    $list->set('warehouse', Warehouse::factory()->create()->id)->assertSet('warehouse', $this->stock->warehouse->id);

    // "Clear filters" goes back to the default too.
    $list->set('warehouse', 'all')->call('resetFilters')->assertSet('warehouse', $this->stock->warehouse->id);
});

it('sets the default warehouse in Settings, among the ticked ones', function () {
    $user = userWithRole('OPERATOR', $this->stock->warehouse);

    $form = Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->assertSet('defaultWarehouseId', $this->stock->warehouse->id)
        ->set('warehouseIds', [$this->stock->warehouse->id, $this->tripoli->id])
        ->set('defaultWarehouseId', $this->tripoli->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->defaultWarehouseId())->toBe($this->tripoli->id);

    // Unticking the default clears it; with one warehouse left it is that one.
    $form->call('edit', $user->id)
        ->set('warehouseIds', [$this->stock->warehouse->id])
        ->assertSet('defaultWarehouseId', $this->stock->warehouse->id)
        ->set('warehouseIds', [$this->stock->warehouse->id, $this->tripoli->id])
        ->set('defaultWarehouseId', '')
        ->call('save')
        ->assertHasErrors('defaultWarehouseId');
});

it('counts waiting approvals and arriving transfers across all the user\'s warehouses', function () {
    $receiver = userWithRole('MANAGER', $this->stock->warehouse, $this->tripoli);
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    $this->stock->dispatch([$this->stock->line('BEANS', 1)], $this->tripoli);

    $this->actingAs($receiver)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('home.arriving'))
        ->assertSee('data-waiting="active"', false);
});

it('explains when the user has no warehouse', function () {
    $user = userWithWarehouses();
    $user->warehouses()->detach();

    $this->actingAs($user)
        ->get(route('receive'))
        ->assertForbidden()
        ->assertSee(__('app.no_warehouses_title'));
});
