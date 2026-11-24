<?php

use App\Livewire\Drafts\DraftForm;
use App\Livewire\Settings\Branches;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Warehouse;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * A branch is served by one warehouse (customer_branch.warehouse_id, required
 * in Settings › Branches). Choosing the branch on an Order or a Draft receipt
 * moves the draft to that warehouse; the user may still switch to another of
 * theirs.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->depot = Warehouse::factory()->create(['code' => 'DPX', 'name' => 'Depot X']);
    $this->stock->user->warehouses()->attach($this->depot->id);
    startIn($this->stock->user, $this->stock->warehouse);
    $this->downtown = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown', 'warehouse_id' => $this->depot->id]);
    $this->uptown = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'UP', 'name' => 'Uptown', 'warehouse_id' => $this->stock->warehouse->id]);
});

// Settings › Branches

it('requires the warehouse of a branch and lists it', function () {
    $other = Warehouse::factory()->create(['name' => 'Closed Depot', 'active' => false]);
    $old = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'OL', 'name' => 'Old Town']);

    $page = Livewire::actingAs(userWithRole('MANAGER'))->test(Branches::class)->call('loadRows')
        ->assertSee(__('settings.branches.warehouse'))
        ->assertSee('Depot X')
        ->assertSee(__('settings.branches.no_warehouse'));

    // Missing, or a warehouse no longer in use: refused.
    $page->call('add')
        ->set('form.customer_id', $this->stock->customer->id)
        ->set('form.name', 'Harbour')->set('form.code', 'HB')->set('form.manager_email', 'hb@acme.test')
        ->call('save')
        ->assertHasErrors(['form.warehouse_id' => 'required'])
        ->assertSee(__('settings.branches.errors.warehouse_required'))
        ->set('form.warehouse_id', $other->id)
        ->call('save')
        ->assertHasErrors(['form.warehouse_id' => 'exists'])
        ->set('form.warehouse_id', $this->depot->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(CustomerBranch::where('code', 'HB')->value('warehouse_id'))->toBe($this->depot->id);

    // A branch from before the field asks for it before it can be saved again.
    $page->call('edit', $old->id)->assertSet('form.warehouse_id', '')
        ->set('form.manager_email', 'ol@acme.test')
        ->call('save')
        ->assertHasErrors(['form.warehouse_id' => 'required']);
});

// Order and Draft receipt

it('moves the draft to the branch warehouse, and lets the user switch to another of theirs', function (string $type) {
    $form = Livewire::actingAs($this->stock->user)->test(DraftForm::class, ['type' => $type])
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->call('chooseCustomer', $this->stock->customer->id)
        ->set('branchId', $this->downtown->id)
        ->assertSet('warehouseId', $this->depot->id)
        ->assertDontSeeHtml('data-branch-warehouse');

    // Back to the other branch: back to its warehouse.
    $form->set('branchId', $this->uptown->id)->assertSet('warehouseId', $this->stock->warehouse->id);

    // The user may still pick another of their warehouses; the hint names the branch's.
    $form->call('chooseWarehouse', $this->depot->id)
        ->assertSet('warehouseId', $this->depot->id)
        ->assertSet('branchId', $this->uptown->id)
        ->assertSee(__('drafts.form.branch_warehouse', ['branch' => 'Uptown', 'warehouse' => $this->stock->warehouse->name]));
})->with(['OUT', 'IN']);

it('asks before the branch warehouse clears lines already entered', function () {
    $form = Livewire::actingAs($this->stock->user)->test(DraftForm::class, ['type' => 'IN'])
        ->call('chooseCustomer', $this->stock->customer->id)
        ->set('branchId', $this->uptown->id);
    [$row] = array_keys($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '3');

    $form->set('branchId', $this->downtown->id)
        ->assertSet('pendingWarehouse', $this->depot->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->call('keepWarehouse')
        ->assertSet("lines.$row.qty", '3')
        ->assertSee(__('drafts.form.branch_warehouse', ['branch' => 'Downtown', 'warehouse' => 'Depot X']));
});

it('keeps the warehouse when the branch is served by one the user cannot use', function () {
    $far = Warehouse::factory()->create(['name' => 'Far Depot']);
    $this->downtown->update(['warehouse_id' => $far->id]);

    Livewire::actingAs($this->stock->user)->test(DraftForm::class, ['type' => 'OUT'])
        ->call('chooseCustomer', $this->stock->customer->id)
        ->set('branchId', $this->downtown->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->assertSee(__('drafts.form.branch_warehouse', ['branch' => 'Downtown', 'warehouse' => 'Far Depot']));
});

it('starts on the warehouse of the only branch a customer has', function () {
    $customer = Customer::factory()->create(['name' => 'Solo Foods']);
    CustomerBranch::create(['customer_id' => $customer->id, 'code' => 'S1', 'name' => 'Solo', 'warehouse_id' => $this->depot->id]);

    Livewire::actingAs($this->stock->user)->test(DraftForm::class, ['type' => 'OUT'])
        ->call('chooseCustomer', $customer->id)
        ->assertSet('warehouseId', $this->depot->id);
});
