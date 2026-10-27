<?php

use App\Livewire\Transactions\TransactionList;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * Each list (and its details and print pages) has its own view permission;
 * creating or approving a type opens them too, so saving never ends on a
 * page the user may not open.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    $this->deliveryNote = $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]);
});

it('opens only the lists the user may view', function () {
    $viewer = userWithPermissions($this->stock->warehouse, ['stock.in.view']);

    $this->actingAs($viewer);
    $this->get(route('receive'))->assertOk();
    $this->get(route('receive.show', $this->receipt->doc_no))->assertOk();
    $this->get(route('receive.print', $this->receipt->doc_no))->assertOk();

    $this->get(route('delivery-note'))->assertForbidden();
    $this->get(route('delivery-note.show', $this->deliveryNote->doc_no))->assertForbidden();
    $this->get(route('delivery-note.print', $this->deliveryNote->doc_no))->assertForbidden();
    $this->get(route('draft-receipts'))->assertForbidden();

    // Viewing is not creating.
    $this->get(route('receive.create'))->assertForbidden();
    Livewire::actingAs($viewer)->test(TransactionList::class, ['type' => 'OUT'])->assertForbidden();
});

it('shows only the sidebar items of the lists the user may open', function () {
    $html = $this->actingAs(userWithPermissions($this->stock->warehouse, ['stock.view', 'stock.in.view', 'draft.out.view']))
        ->get(route('home'))
        ->getContent();

    expect($html)->toContain('href="'.route('receive').'"')
        ->toContain('href="'.route('orders').'"')
        ->not->toContain('href="'.route('delivery-note').'"')
        ->not->toContain('href="'.route('transfer').'"')
        ->not->toContain('href="'.route('draft-receipts').'"');
});

it('lets whoever may create, approve or receive a type open its list too', function (array $codes, string $route) {
    $this->actingAs(userWithPermissions($this->stock->warehouse, $codes))
        ->get(route($route))
        ->assertOk();
})->with([
    'create a delivery note' => [['stock.out.create'], 'delivery-note'],
    'approve damage' => [['stock.damage.approve'], 'damage'],
    'receive transfers' => [['stock.transfer.receive'], 'transfer'],
    'confirm draft receipts' => [['draft.in.confirm'], 'draft-receipts'],
]);

it('opens a draft list to view without creating', function () {
    $this->actingAs(userWithPermissions($this->stock->warehouse, ['draft.in.view']));

    $this->get(route('draft-receipts'))->assertOk()->assertDontSee(route('draft-receipts.create'), false);
    $this->get(route('draft-receipts.create'))->assertForbidden();
});

it('closes a list once its view permission is taken away', function () {
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);
    $this->actingAs($operator)->get(route('adjustment'))->assertOk();

    // Operators create adjustments, so they still see them; Opening Balance they only viewed.
    DB::table('role_permission')
        ->where('role_id', Role::where('code', 'OPERATOR')->value('id'))
        ->whereIn('permission_id', DB::table('permission')->whereIn('code', ['stock.opening.view', 'stock.adjustment.view'])->pluck('id'))
        ->delete();
    Permissions::flush();

    $this->actingAs(User::find($operator->id))->get(route('opening-balance'))->assertForbidden();
    $this->actingAs(User::find($operator->id))->get(route('adjustment'))->assertOk();
});
