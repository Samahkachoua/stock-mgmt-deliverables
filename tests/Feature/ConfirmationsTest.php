<?php

use App\Livewire\Concerns\ConfirmsActions;
use App\Livewire\Items\ItemForm;
use App\Livewire\Settings\ApprovalLimits;
use App\Livewire\Settings\Customers;
use App\Livewire\Settings\Roles;
use App\Livewire\Settings\Units;
use App\Livewire\Settings\Users;
use App\Livewire\Settings\Warehouses;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\DecideApproval;
use App\Livewire\Transactions\ReceiveTransfer;
use App\Livewire\Transactions\TransactionForm;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\PostingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Catalog;
use Tests\Support\Stock;

/*
 * CLAUDE.md "Confirmations": deleting, deactivating, cancelling and rejecting
 * ask first in the shared modal; saving, approving and confirming a receipt
 * never do. The modal is only UI: the server checks again.
 */

/**
 * A Damage waiting for approval, created by $by.
 */
function damageAwaitingApproval(Stock $stock, User $by): StockTransaction
{
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);

    return $stock->post('DAMAGE', [$stock->line('BEANS', 12)], $by, reason: 'HANDLING');
}

/**
 * What audit_log kept of the last $action on a document.
 *
 * @return array<string, mixed>
 */
function lastAudit(StockTransaction $transaction, string $action): array
{
    return json_decode((string) DB::table('audit_log')
        ->where('entity', 'stock_transaction')->where('entity_id', $transaction->id)->where('action', $action)
        ->orderByDesc('id')->value('after_data'), true);
}

// Deleting

it('asks before deleting a unit, and dismissing does nothing', function () {
    $unit = Unit::create(['code' => 'DRM', 'name' => 'Drum']);

    $page = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(Units::class)->call('askDelete', $unit->id);

    // Nothing is deleted yet: the modal names the action and the record.
    expect(Unit::find($unit->id))->not->toBeNull();
    $page->assertSet('confirm.title', 'Delete unit '.$unit->label().'?')
        ->assertSet('confirm.button', 'Delete unit')
        ->assertSet('confirm.reason', null)
        ->assertSee('can’t be undone')
        ->assertSee(__('confirm.keep'));

    // "Keep it", Esc and a click outside all end here.
    $page->call('dismissConfirm')->assertSet('confirm', null)->call('runConfirmed');
    expect(Unit::find($unit->id))->not->toBeNull();

    $page->call('askDelete', $unit->id)->call('runConfirmed')->assertDispatched('toast');
    expect(Unit::find($unit->id))->toBeNull();
});

it('shows why a record can’t be deleted instead of the modal', function () {
    Catalog::create(); // items counted in Piece, sold by the Box
    $box = Unit::where('code', 'BOX')->firstOrFail();
    $items = DB::table('product_unit')->where('unit_id', $box->id)->distinct()->count('product_id');

    Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(Units::class)
        ->call('askDelete', $box->id)
        ->assertSet('confirm', null)
        ->assertSee(trans_choice('confirm.blocked.unit_in_use', $items, ['name' => $box->label(), 'count' => (string) $items]));

    // A built-in role, and a role someone still has.
    $temp = Role::create(['code' => 'TEMP', 'name' => 'Temp']);
    userWithRole('TEMP');

    Livewire::actingAs(userWithRole('ADMIN'))->test(Roles::class)
        ->call('askDelete', $temp->id)
        ->assertSet('confirm', null)
        ->assertSee('Temp is given to 1 user and can’t be deleted.')
        ->call('askDelete', Role::where('code', 'AUDITOR')->value('id'))
        ->assertSet('confirm', null)
        // Built-in roles have no Delete button; a forged request is still refused.
        ->assertHasErrors('delete.'.Role::where('code', 'AUDITOR')->value('id'));

    expect(Unit::find($box->id))->not->toBeNull()
        ->and(Role::where('code', 'TEMP')->exists())->toBeTrue();
});

it('asks before deleting a role and before deleting saved approval limits', function () {
    $role = Role::create(['code' => 'SHIFT_LEAD', 'name' => 'Shift lead']);
    $admin = userWithRole('ADMIN');

    $roles = Livewire::actingAs($admin)->test(Roles::class)->call('askDelete', $role->id)
        ->assertSet('confirm.title', 'Delete role Shift lead?')
        ->assertSet('confirm.button', 'Delete role');
    expect(Role::find($role->id))->not->toBeNull();
    $roles->call('runConfirmed');
    expect(Role::find($role->id))->toBeNull();

    $acme = Customer::factory()->create(['name' => 'Acme Foods']);
    DB::table('approval_threshold')->insert(['scope' => 'CUSTOMER', 'scope_id' => $acme->id, 'transaction_type' => 'DAMAGE', 'qty_base' => 5]);
    $saved = fn () => DB::table('approval_threshold')->where('scope', 'CUSTOMER')->count();

    $limits = Livewire::actingAs($admin)->test(ApprovalLimits::class)->call('removeCustomer', $acme->id)
        ->assertSet('confirm.title', 'Delete the approval limits of Acme Foods?');
    expect($saved())->toBe(1);

    $limits->call('dismissConfirm');
    expect($saved())->toBe(1)->and($limits->get('customerLimits'))->toHaveKey($acme->id);

    $limits->call('removeCustomer', $acme->id)->call('runConfirmed');
    expect($saved())->toBe(0)->and($limits->get('customerLimits'))->not->toHaveKey($acme->id);
});

it('asks before deleting an item nothing uses, and removes it with its units', function () {
    $catalog = Catalog::create();
    $product = $catalog->items['FIL-01'];

    $form = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(ItemForm::class, ['product' => $product])
        ->assertSee(__('items.delete'))
        ->call('askDelete')
        ->assertSet('deleteBlocked', null)
        ->assertSet('confirm.title', __('confirm.delete.item.title', ['name' => 'Filler 01']))
        ->assertSet('confirm.button', __('confirm.delete.item.button'));

    // "Keep it": nothing happens.
    $form->call('dismissConfirm');
    expect(Product::find($product->id))->not->toBeNull();

    $form->call('askDelete')->call('runConfirmed')->assertRedirect(route('items'));

    expect(Product::find($product->id))->toBeNull()
        ->and(DB::table('product_unit')->where('product_id', $product->id)->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('entity', 'product')->where('entity_id', $product->id)->where('action', 'DELETE')->exists())->toBeTrue()
        ->and(session('toasts')[0]['message'])->toBe(__('items.deleted', ['name' => 'Filler 01']));
});

it('says which documents use an item instead of asking to delete it', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);
    $stock->post('OUT', [$stock->line('BEANS', 2)]);
    $stock->post('OUT', [$stock->line('BEANS', 1)]);
    $product = Product::findOrFail($stock->id('BEANS'));

    Livewire::actingAs($stock->user)->test(ItemForm::class, ['product' => $product])
        ->call('askDelete')
        ->assertSet('confirm', null)
        ->assertSet('deleteBlocked', trans_choice('confirm.blocked.item_on_documents', 3, [
            'name' => $product->name(), 'count' => '3', 'types' => __('transactions.types.receive.singular').', '.__('transactions.types.delivery_note.singular'),
        ]))
        ->assertSee(__('transactions.types.delivery_note.singular'))
        // Save stays available: the message is not a form error.
        ->assertHasNoErrors();

    expect(Product::find($product->id))->not->toBeNull();
});

it('checks again when the item delete is confirmed', function () {
    $stock = Stock::create();
    $product = Product::findOrFail($stock->id('BEANS'));

    $form = Livewire::actingAs($stock->user)->test(ItemForm::class, ['product' => $product])->call('askDelete');
    expect($form->get('confirm'))->not->toBeNull();

    // Meanwhile someone receives it.
    $stock->post('IN', [$stock->line('BEANS', 5)]);

    $form->call('runConfirmed')->assertNoRedirect();
    expect($form->get('deleteBlocked'))->toContain($product->name())
        ->and(Product::find($product->id))->not->toBeNull();
});

it('removes a customer row that was never saved without asking', function () {
    $acme = Customer::factory()->create();

    Livewire::actingAs(userWithRole('ADMIN'))->test(ApprovalLimits::class)
        ->set('newCustomer', $acme->id)
        ->call('removeCustomer', $acme->id)
        ->assertSet('confirm', null)
        ->assertSet('customerLimits', []);
});

// Deactivating

it('asks before deactivating a warehouse, customer, user or item', function () {
    $admin = userWithRole('ADMIN');
    $warehouse = Warehouse::factory()->create(['name' => 'Old Depot']);
    $customer = Customer::factory()->create(['name' => 'Acme']);
    $colleague = userWithRole('OPERATOR');
    $catalog = Catalog::create();
    $item = $catalog->items['CB-400'];

    $pages = [
        [Livewire::actingAs($admin)->test(Warehouses::class)->call('edit', $warehouse->id)->set('form.active', false), $warehouse, 'Deactivate warehouse Old Depot?', 'Deactivate warehouse'],
        [Livewire::actingAs($admin)->test(Customers::class)->call('edit', $customer->id)->set('form.active', false), $customer, 'Deactivate customer Acme?', 'Deactivate customer'],
        [Livewire::actingAs($admin)->test(Users::class)->call('edit', $colleague->id)->set('active', false), $colleague, 'Deactivate user '.$colleague->full_name.'?', 'Deactivate user'],
        [Livewire::actingAs($admin)->test(ItemForm::class, ['product' => $item])->set('active', false), $item, 'Deactivate item '.$item->name().'?', 'Deactivate item'],
    ];

    foreach ($pages as [$page, $record, $title, $button]) {
        $page->call('save')
            ->assertSet('confirm.title', $title)
            ->assertSet('confirm.button', $button);
        expect($record->fresh()->active)->toBeTrue($title);

        // Dismissed: still active.
        $page->call('dismissConfirm');
        expect($record->fresh()->active)->toBeTrue($title);

        $page->call('save')->call('runConfirmed');
        expect($record->fresh()->active)->toBeFalse($title);
    }
});

it('saves other changes, and activating, without asking', function () {
    $customer = Customer::factory()->create(['name' => 'Acme', 'active' => false]);

    Livewire::actingAs(userWithRole('ADMIN'))->test(Customers::class)
        ->call('edit', $customer->id)
        ->set('form.name', 'Acme Foods')
        ->set('form.active', true)
        ->call('save')
        ->assertSet('confirm', null);

    expect($customer->fresh())->name->toBe('Acme Foods')->active->toBeTrue();
});

it('says why a warehouse with stock can’t be deactivated instead of asking', function () {
    $catalog = Catalog::create();

    Livewire::actingAs(userWithRole('ADMIN'))->test(Warehouses::class)
        ->call('edit', $catalog->warehouse->id)
        ->set('form.active', false)
        ->call('save')
        ->assertSet('confirm', null)
        ->assertSee(__('settings.warehouses.errors.has_stock'));

    expect($catalog->warehouse->fresh()->active)->toBeTrue();
});

// Cancelling

it('asks before cancelling, with the consequence and a required reason kept in the audit log', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 60)]);
    $deliveryNote = $stock->post('OUT', [$stock->line('BEANS', 3, 'BOX')]);

    $page = Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $deliveryNote->id])->call('ask');

    $page->assertSet('confirm.title', 'Cancel delivery note '.$deliveryNote->doc_no.'?')
        ->assertSet('confirm.body', 'This returns 3 Box of Canned Beans to Beirut Main stock and can’t be undone.')
        ->assertSet('confirm.button', 'Cancel delivery note')
        ->assertSee(__('confirm.cancel.reason'));
    expect($deliveryNote->fresh()->status)->toBe('POSTED');

    // No reason: nothing happens, the modal stays.
    $page->call('runConfirmed')->assertHasErrors('confirmReason')->assertSee(__('confirm.reason_required'))->assertNoRedirect();
    expect($deliveryNote->fresh()->status)->toBe('POSTED')->and($page->get('confirm'))->not->toBeNull();

    // Dismissed: nothing happens either.
    $page->call('dismissConfirm')->call('runConfirmed');
    expect($deliveryNote->fresh()->status)->toBe('POSTED');

    $page->call('ask')->set('confirmReason', '  Entered for the wrong customer ')->call('runConfirmed')
        ->assertRedirect(route('delivery-note.show', $deliveryNote->doc_no));

    expect($deliveryNote->fresh()->status)->toBe('CANCELLED')
        ->and($stock->balance('BEANS'))->toBe(60.0)
        ->and(lastAudit($deliveryNote, 'CANCEL')['reason'])->toBe('Entered for the wrong customer');

    // The details page shows it in the Activity list.
    $this->actingAs($stock->user)->get(route('delivery-note.show', $deliveryNote->doc_no))
        ->assertSee(__('transactions.activity.heading'))
        ->assertSee('Entered for the wrong customer');
});

it('says what a cancelled receipt takes out of stock, and counts the other lines', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10), $stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);

    Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])->call('ask')
        ->assertSet('confirm.body', 'This takes 10 Piece of Canned Beans and 1 other item out of Beirut Main stock again and can’t be undone.');
});

it('explains instead of asking when a document can’t be cancelled', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10)]);
    $stock->post('OUT', [$stock->line('BEANS', 4)]);

    Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])->call('ask')
        ->assertSet('confirm', null)
        ->assertSee(__('stock.errors.already_used', ['item' => 'Canned Beans', 'received' => '10 Piece', 'left' => '6 Piece']))
        ->assertDispatched('toast');

    // Asking changed nothing (the check runs the cancellation and rolls it back).
    expect($receipt->fresh()->status)->toBe('POSTED')
        ->and($stock->balance('BEANS'))->toBe(6.0)
        ->and(DB::table('audit_log')->where('entity_id', $receipt->id)->where('action', 'CANCEL')->count())->toBe(0)
        ->and(Stock::ledgerMismatches())->toBe([]);
});

it('refuses to cancel without a reason in the service too', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10)]);

    expect(fn () => app(PostingService::class)->cancel($receipt, $stock->user, '   '))
        ->toThrow(PostingRejected::class, __('stock.errors.cancel_reason_required'));

    expect($receipt->fresh()->status)->toBe('POSTED');
});

// Rejecting

it('asks before rejecting, with a required reason kept in the audit log and shown in the activity', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 36)]);
    $operator = userWithRole('OPERATOR', $stock->warehouse);
    $manager = userWithRole('MANAGER', $stock->warehouse);
    $damage = damageAwaitingApproval($stock, $operator);

    $page = Livewire::actingAs($manager)->test(DecideApproval::class, ['transactionId' => $damage->id])->call('startReject');

    $page->assertSet('confirm.title', 'Reject damage record '.$damage->doc_no.'?')
        ->assertSet('confirm.button', 'Reject damage record')
        ->assertSee($operator->full_name)
        ->call('runConfirmed')
        ->assertHasErrors('confirmReason');
    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL');

    $page->call('dismissConfirm')->call('runConfirmed');
    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL');

    $page->call('startReject')->set('confirmReason', 'Photos show the boxes are fine')->call('runConfirmed')
        ->assertRedirect(route('damage.show', $damage->doc_no));

    expect($damage->fresh()->status)->toBe('REJECTED')
        ->and($stock->balance('BEANS'))->toBe(36.0)
        ->and(lastAudit($damage, 'REJECT')['reason'])->toBe('Photos show the boxes are fine');

    $this->actingAs($operator)->get(route('damage.show', $damage->doc_no))
        ->assertSee(str_replace(':reason', '', __('transactions.activity.reason')))
        ->assertSee('Photos show the boxes are fine');
});

// What never asks

it('never asks for saving what can be cancelled, approving, confirming a receipt or removing an unsaved line', function () {
    // The receipt confirmation has no confirmation at all.
    expect(class_uses_recursive(ReceiveTransfer::class))->not->toContain(ConfirmsActions::class);

    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 36)]);

    // A receipt can be cancelled later: Save posts at once.
    $receipt = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'IN'])->call('chooseCustomer', $stock->customer->id);
    $row = array_key_first($receipt->get('lines'));
    $receipt->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '1')
        ->call('save')
        ->assertSet('confirm', null)
        ->assertRedirect();
    expect($stock->balance('BEANS'))->toBe(37.0);

    // Sending for approval changes no stock yet: no question either.
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);
    $operator = userWithRole('OPERATOR', $stock->warehouse);
    $pending = Livewire::actingAs($operator)->test(TransactionForm::class, ['type' => 'DAMAGE'])
        ->call('chooseCustomer', $stock->customer->id)->set('reason', 'HANDLING');
    $row = array_key_first($pending->get('lines'));
    $pending->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '12')
        ->call('save')
        ->assertSet('confirm', null)
        ->assertRedirect();
    DB::table('approval_threshold')->delete();
    $manager = userWithRole('MANAGER', $stock->warehouse);
    $damage = damageAwaitingApproval($stock, userWithRole('OPERATOR', $stock->warehouse));

    // Approve posts at once.
    Livewire::actingAs($manager)->test(DecideApproval::class, ['transactionId' => $damage->id])
        ->call('approve')
        ->assertSet('confirm', null)
        ->assertRedirect(route('damage.show', $damage->doc_no));
    expect($damage->fresh()->status)->toBe('POSTED');

    // A line of a form that isn't saved yet just goes.
    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'IN'])->call('chooseCustomer', $stock->customer->id);
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '2')->call('deleteLine', $row);
    expect($form->get('lines'))->not->toHaveKey($row);
});

it('asks before saving a damage or adjustment that changes stock at once and can’t be cancelled', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 36)]);

    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'DAMAGE'])
        ->call('chooseCustomer', $stock->customer->id)->set('reason', 'HANDLING');
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '4')->call('save');

    // Names the action and the customer, says what changes, and that it is final.
    expect($form->get('confirm'))->toMatchArray([
        'action' => 'save',
        'title' => __('transactions.form.confirm_final.title', ['action' => __('transactions.types.damage.save'), 'customer' => $stock->customer->name]),
        'button' => __('transactions.types.damage.save'),
    ])->and($form->get('confirm')['body'])->toContain($stock->warehouse->name);
    $form->assertSee(__('confirm.keep'));

    // "Keep it": nothing saved, the lines stay.
    $form->call('dismissConfirm')->assertSet('confirm', null)->assertSet("lines.$row.qty", '4');
    expect(StockTransaction::query()->where('transaction_type', 'DAMAGE')->exists())->toBeFalse()
        ->and($stock->balance('BEANS'))->toBe(36.0);

    // Confirmed: posted, and on to the document.
    $form->call('save')->call('runConfirmed');
    $damage = StockTransaction::query()->where('transaction_type', 'DAMAGE')->sole();
    $form->assertRedirect(route('damage.show', $damage->doc_no));
    expect($damage->status)->toBe('POSTED')->and($stock->balance('BEANS'))->toBe(32.0);
});

it('asks before saving an opening balance, without saying it can’t be cancelled', function () {
    $stock = Stock::create();

    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'OPENING_BALANCE'])
        ->call('chooseCustomer', $stock->customer->id);
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '24')->call('save');

    // It can be cancelled later, so the question says to check, not that it is final.
    expect($form->get('confirm'))->toMatchArray([
        'action' => 'save',
        'title' => __('transactions.form.confirm_final.title', ['action' => __('transactions.types.opening.save'), 'customer' => $stock->customer->name]),
        'button' => __('transactions.types.opening.save'),
    ])->and($form->get('confirm')['body'])->toBe(__('transactions.form.confirm_final.body_cancellable', ['warehouse' => $stock->warehouse->name, 'summary' => '1 item · 24 Piece']));

    // "Keep it": nothing saved.
    $form->call('dismissConfirm')->assertSet('confirm', null)->assertSet("lines.$row.qty", '24');
    expect(StockTransaction::query()->where('transaction_type', 'OPENING_BALANCE')->exists())->toBeFalse()
        ->and($stock->balance('BEANS'))->toBe(0.0);

    // Confirmed: posted, and on to the document.
    $form->call('save')->call('runConfirmed');
    $opening = StockTransaction::query()->where('transaction_type', 'OPENING_BALANCE')->sole();
    $form->assertRedirect(route('opening-balance.show', $opening->doc_no));
    expect($opening->status)->toBe('POSTED')->and($stock->balance('BEANS'))->toBe(24.0);
});

it('checks the form again when a final save is confirmed', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 36)]);

    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'DAMAGE'])
        ->call('chooseCustomer', $stock->customer->id)->set('reason', 'HANDLING');
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '4')->call('save');

    // Meanwhile the stock went out.
    $stock->post('OUT', [$stock->line('BEANS', 34)]);

    $form->call('runConfirmed');
    expect(StockTransaction::query()->where('transaction_type', 'DAMAGE')->exists())->toBeFalse()
        ->and($stock->balance('BEANS'))->toBe(2.0);
});

// The server checks again

it('refuses on the server when the modal is bypassed', function () {
    $unit = Unit::create(['code' => 'DRM', 'name' => 'Drum']);
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 3)]);
    $operator = userWithRole('OPERATOR', $stock->warehouse);

    // Without permission the modal doesn't even open.
    Livewire::actingAs($operator)->test(CancelTransaction::class, ['transactionId' => $receipt->id])->call('ask')->assertForbidden();

    // The actions behind the modal can't be called from the browser...
    $units = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(Units::class);
    expect(fn () => $units->call('delete', $unit->id))->toThrow(Exception::class)
        ->and(fn () => $units->call('confirmed', 'delete', $unit->id, ''))->toThrow(Exception::class);

    // ...the pending confirmation can't be forged...
    expect(fn () => $units->set('confirm', ['action' => 'delete', 'id' => $unit->id, 'title' => '', 'body' => '', 'button' => '', 'reason' => null, 'placeholder' => null]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    // ...and confirming with nothing asked does nothing.
    $units->call('runConfirmed');
    Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->set('confirmReason', 'No modal was opened')->call('runConfirmed');

    expect(Unit::find($unit->id))->not->toBeNull()
        ->and($receipt->fresh()->status)->toBe('POSTED');
});

it('checks permission and rules again when the confirmation arrives', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 36)]);
    $operator = userWithRole('OPERATOR', $stock->warehouse);
    $manager = userWithRole('MANAGER', $stock->warehouse);
    $damage = damageAwaitingApproval($stock, $operator);

    // The modal is open; meanwhile another manager approves the document.
    $page = Livewire::actingAs($manager)->test(DecideApproval::class, ['transactionId' => $damage->id])->call('startReject');
    app(PostingService::class)->approve($damage, userWithRole('MANAGER', $stock->warehouse));

    $page->set('confirmReason', 'Too late')->call('runConfirmed')->assertForbidden();
    expect($damage->fresh()->status)->toBe('POSTED');

    // A unit that came into use while its modal was open is not deleted.
    $unit = Unit::create(['code' => 'DRM', 'name' => 'Drum']);
    $units = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(Units::class)->call('askDelete', $unit->id);
    DB::table('product_unit')->insert(['product_id' => $stock->id('BEANS'), 'unit_id' => $unit->id, 'factor_to_base' => 200, 'is_default' => false]);

    $units->call('runConfirmed')->assertHasErrors('delete.'.$unit->id);
    expect(Unit::find($unit->id))->not->toBeNull();
});

// The modal itself

it('draws one shared modal: safe button focused, Esc and outside click close, loading on the action', function () {
    $unit = Unit::create(['code' => 'DRM', 'name' => 'Drum']);

    $html = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(Units::class)->call('askDelete', $unit->id)->html();
    $modal = substr($html, (int) strpos($html, 'x-trap.noscroll.inert'));

    expect($html)->toContain('x-trap.noscroll.inert')
        ->and($modal)
        ->toContain('role="alertdialog"')->toContain('aria-modal="true"')
        ->toContain('x-on:keydown.escape.window="$wire.dismissConfirm()"')
        ->toContain('x-on:click="$wire.dismissConfirm()"')
        ->toContain('wire:click="runConfirmed"')
        ->toContain('wire:target="runConfirmed"')
        ->toContain(__('app.loading.saving'))
        ->toContain('bg-type-damage-strong');

    // "Keep it" comes first and has the focus.
    preg_match('/<button\s[^>]*autofocus[^>]*>\s*([^<]+)/s', $modal, $focused);
    expect(trim($focused[1] ?? ''))->toBe(__('confirm.keep'));
});

it('never uses the browser’s confirm() or wire:confirm', function () {
    $files = [];

    foreach ([resource_path('views'), resource_path('js')] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            // Comments may mention it; code may not.
            $files[$file->getPathname()] = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));
        }
    }

    foreach ($files as $path => $source) {
        expect($source)->not->toContain('wire:confirm', $path)
            ->and(preg_match('/(?<![\w.$>])confirm\(/', $source))->toBe(0, $path);
    }
});

it('has the confirmation texts in each language, with the same parameters', function () {
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = array_unique($found[0]);
        sort($names);

        return $names;
    };

    foreach (Arr::dot(Lang::get('confirm', [], 'en')) as $key => $english) {
        foreach (['ar', 'fr'] as $locale) {
            expect(Lang::hasForLocale("confirm.$key", $locale))->toBeTrue("confirm.$key is missing in $locale")
                ->and($placeholders(Lang::get("confirm.$key", [], $locale)))->toBe($placeholders($english), "confirm.$key in $locale");
        }
    }
});
