<?php

use App\Livewire\Drafts\DraftForm;
use App\Livewire\Drafts\DraftList;
use App\Livewire\Drafts\DraftPage;
use App\Livewire\Settings\Branches;
use App\Livewire\Settings\Users;
use App\Livewire\Transactions\TransactionList;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\StockDraft;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Support\Format;
use App\Support\Permissions;
use App\Support\StorageStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->depot = Warehouse::factory()->create(['code' => 'DPX', 'name' => 'Depot X']);
    $this->stock->user->warehouses()->attach($this->depot->id);
    // Pages start in Beirut Main, the user's default warehouse.
    startIn($this->stock->user, $this->stock->warehouse);
    $this->branch = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);
});

/**
 * A saved draft of $type in $warehouse (Beirut Main by default) with BEANS
 * lines in Piece: one quantity each.
 *
 * @param  list<int>  $lines
 */
function savedDraft(object $test, string $type, array $lines, ?Warehouse $warehouse = null): StockDraft
{
    $keyed = [];
    foreach ($lines as $i => $qty) {
        $keyed['r'.($i + 1)] = new DraftLineInput($test->stock->id('BEANS'), 'PC', $qty);
    }

    return app(DraftService::class)->save(
        new DraftInput($type, $test->stock->customer->id, $test->branch->id, ($warehouse ?? $test->stock->warehouse)->id, CarbonImmutable::today(), null, $keyed, deliveryDate: CarbonImmutable::today(), priority: 'NORMAL'),
        $test->stock->user,
    );
}

function draftForm(object $test, string $type = 'IN', ?string $reference = null): Testable
{
    return Livewire::actingAs($test->stock->user)->test(DraftForm::class, array_filter(['type' => $type, 'reference' => $reference]));
}

it('saves a new draft receipt in the warehouse chosen in its header, touching no stock', function () {
    $form = draftForm($this)
        // The user's default warehouse is pre-selected.
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->call('chooseCustomer', $this->stock->customer->id)
        // The only branch is chosen already.
        ->assertSet('branchId', $this->branch->id)
        // No lines yet: the warehouse changes without asking.
        ->call('chooseWarehouse', $this->depot->id)
        ->assertSet('warehouseId', $this->depot->id)
        ->assertSet('pendingWarehouse', null)
        ->assertSee(__('drafts.types.receipt.warehouse_hint'));

    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))
        ->set("lines.$first.qty", '2')
        ->set("lines.$first.unit", 'BOX');

    [, $second] = array_keys($form->get('lines'));
    $form->set("lines.$second.item", $this->stock->id('BEANS'))
        ->set("lines.$second.qty", '5')
        ->call('save')
        ->assertHasNoErrors();

    $draft = StockDraft::withoutGlobalScopes()->firstOrFail();

    $form->assertRedirect(route('draft-receipts.show', $draft->doc_no));

    expect($draft->warehouse_id)->toBe($this->depot->id)
        ->and(DB::table('stock_draft_line')->where('draft_id', $draft->id)->orderBy('line_no')->pluck('qty_base')->map(fn ($q) => (float) $q)->all())->toBe([24.0, 5.0])
        ->and(DB::table('stock_movement')->count())->toBe(0);
});

it('asks before another warehouse clears the lines entered', function () {
    $form = draftForm($this)->call('chooseCustomer', $this->stock->customer->id);
    [$row] = array_keys($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '3');

    // Asked first; keeping the warehouse keeps the line.
    $form->call('chooseWarehouse', $this->depot->id)
        ->assertSet('pendingWarehouse', $this->depot->id)
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->assertSee(trans_choice('transactions.form.change_warehouse_warning', 1, ['warehouse' => 'Depot X', 'count' => '1']))
        ->call('keepWarehouse')
        ->assertSet('pendingWarehouse', null)
        ->assertSet("lines.$row.qty", '3');

    // Switching clears it.
    $form->call('chooseWarehouse', $this->depot->id)
        ->call('confirmWarehouseChange')
        ->assertSet('warehouseId', $this->depot->id)
        ->assertSet('lines', fn (array $lines) => count($lines) === 1 && reset($lines)['item'] === null);
});

it('hides the warehouse field from a user with one warehouse', function () {
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);

    Livewire::actingAs($operator)->test(DraftForm::class, ['type' => 'IN'])
        ->assertSet('warehouseId', $this->stock->warehouse->id)
        ->assertDontSeeHtml('id="field-warehouse"');
});

it('refuses more than 999,999 on a draft line, in the form and on save', function () {
    $form = draftForm($this, 'IN')->call('chooseCustomer', $this->stock->customer->id);
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '1000000');
    expect($form->html())->toContain(e(__('transactions.form.errors.too_large', ['max' => '999,999'])));
    $form->call('save');

    expect(StockDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('limits an order row to what is in stock in the order\'s warehouse', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);

    $form = draftForm($this, 'OUT')->call('chooseCustomer', $this->stock->customer->id);
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '8');
    expect($form->html())->toContain('Available');

    // Nothing of it in Depot X.
    $form->call('chooseWarehouse', $this->depot->id)->call('confirmWarehouseChange');
    [$row] = array_keys($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '8');
    expect($form->html())->toContain('Only 0 Piece available.');
    $form->call('save');

    expect(StockDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('edits a draft, and lets a confirmer change a quantity and confirm in one step', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], warehouse: $this->depot);
    $draft = savedDraft($this, 'OUT', [6], $this->depot);

    $form = draftForm($this, 'OUT', $draft->doc_no)
        ->assertSee(__('drafts.actions.save_and_confirm'))
        ->assertSet('warehouseId', $this->depot->id);
    [$first] = array_keys($form->get('lines'));

    $form->set("lines.$first.qty", '3')->call('saveAndConfirm')->assertHasNoErrors();

    // One delivery note, from the order's warehouse only, holding the 3 until delivered.
    expect($draft->fresh()->status)->toBe('POSTED')
        ->and(DB::table('stock_transaction')->where('draft_id', $draft->id)->pluck('warehouse_id')->all())->toBe([$this->depot->id])
        ->and($this->stock->balance('BEANS', warehouse: $this->depot))->toBe(10.0)
        ->and($this->stock->reserved('BEANS', warehouse: $this->depot))->toBe(3.0)
        ->and($this->stock->reserved('BEANS'))->toBe(0.0);
});

it('offers no "Save and confirm" to whoever may not confirm', function () {
    $draft = savedDraft($this, 'IN', [1]);

    Livewire::actingAs(userWithRole('OPERATOR', $this->stock->warehouse))
        ->test(DraftForm::class, ['type' => 'IN', 'reference' => $draft->doc_no])
        ->assertDontSee(__('drafts.actions.save_and_confirm'))
        ->call('saveAndConfirm')
        ->assertForbidden();
});

it('confirms a draft from its details page and shows the receipt created', function () {
    $draft = savedDraft($this, 'IN', [8], $this->depot);

    Livewire::actingAs($this->stock->user)
        ->test(DraftPage::class, ['type' => 'IN', 'reference' => $draft->doc_no])
        ->assertSee('Depot X')
        ->assertSee(__('drafts.details.not_in_stock_in', ['warehouse' => 'Depot X']))
        ->call('confirmDraft')
        ->assertHasNoErrors()
        ->assertRedirect(route('draft-receipts.show', $draft->doc_no));

    $receipts = DB::table('stock_transaction')->where('draft_id', $draft->id)->orderBy('id')->pluck('doc_no')->all();

    Livewire::actingAs($this->stock->user)
        ->test(DraftPage::class, ['type' => 'IN', 'reference' => $draft->doc_no])
        ->assertSee($receipts[0])
        ->assertDontSee(__('drafts.actions.edit'));

    // The receipt names its branch and links back to the draft.
    $this->actingAs($this->stock->user)
        ->get(route('receive.show', $receipts[0]))
        ->assertOk()
        ->assertSee('Downtown')
        ->assertSee(route('draft-receipts.show', $draft->doc_no), false);

    expect($receipts)->toHaveCount(1)
        ->and($this->stock->balance('BEANS', warehouse: $this->depot))->toBe(8.0)
        ->and($this->stock->balance('BEANS'))->toBe(0.0);
});

it('refuses a draft that repeats an item and unit (rule 12)', function () {
    expect(fn () => savedDraft($this, 'IN', [3, 5]))
        ->toThrow(DuplicateLine::class, __('transactions.form.duplicate.unit', ['item' => 'Canned Beans', 'unit' => Format::unit('PC'), 'row' => '1']))
        ->and(StockDraft::count())->toBe(0);
});

it('shows why a confirmation failed under the line concerned', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    $draft = savedDraft($this, 'OUT', [5]);
    // An order saved before reservations existed holds nothing: others could take its stock.
    DB::select('SELECT fn_release_reservations(ARRAY(SELECT id FROM stock_reservation))');
    $this->stock->post('OUT', [$this->stock->line('BEANS', 2)]);
    $line = DB::table('stock_draft_line')->where('draft_id', $draft->id)->value('id');

    Livewire::actingAs($this->stock->user)
        ->test(DraftPage::class, ['type' => 'OUT', 'reference' => $draft->doc_no])
        ->call('confirmDraft')
        ->assertNoRedirect()
        ->assertSet('lineErrors', fn (array $errors) => isset($errors[$line]))
        ->assertSee('Only 3 Piece');

    expect($draft->fresh()->status)->toBe('DRAFT');
});

it('cancels a draft after asking, with a reason', function () {
    $draft = savedDraft($this, 'IN', [1]);

    Livewire::actingAs($this->stock->user)
        ->test(DraftPage::class, ['type' => 'IN', 'reference' => $draft->doc_no])
        ->call('askCancel')
        ->assertSet('confirm.action', 'cancel')
        ->call('runConfirmed')
        ->assertHasErrors('confirmReason')
        ->set('confirmReason', 'Order withdrawn')
        ->call('runConfirmed');

    expect($draft->fresh()->status)->toBe('CANCELLED');
});

it('cancels an order only with the cancel orders permission', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    $order = savedDraft($this, 'OUT', [1]);
    $editor = userWithPermissions($this->stock->warehouse, ['draft.out.view', 'draft.out.create']);
    $canceller = userWithPermissions($this->stock->warehouse, ['draft.out.view', 'draft.out.cancel']);

    Livewire::actingAs($editor)
        ->test(DraftPage::class, ['type' => 'OUT', 'reference' => $order->doc_no])
        ->assertViewHas('canCancel', false)
        ->call('askCancel')
        ->assertForbidden();

    Livewire::actingAs($canceller)
        ->test(DraftPage::class, ['type' => 'OUT', 'reference' => $order->doc_no])
        ->assertViewHas('canCancel', true)
        ->call('askCancel')
        ->set('confirmReason', 'Customer called it off')
        ->call('runConfirmed');

    expect($order->fresh()->status)->toBe('CANCELLED');
});

it('lists drafts the user may see, and opens list, details and print pages', function () {
    $draft = savedDraft($this, 'IN', [1]);

    Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->assertSee($draft->doc_no)
        ->assertSee('Downtown');

    Livewire::actingAs(userWithRole('OPERATOR', $this->depot))->test(DraftList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->assertDontSee($draft->doc_no);

    $this->actingAs($this->stock->user);
    $this->get(route('draft-receipts'))->assertOk();
    $this->get(route('draft-receipts.create'))->assertOk();
    $this->get(route('draft-receipts.show', $draft->doc_no))->assertOk()->assertSee($draft->doc_no);
    $this->get(route('draft-receipts.print', $draft->doc_no))->assertOk()->assertSee('Downtown');
    $this->get(route('home'))->assertSee(route('draft-receipts'), false);
});

it('offers New Draft Receipt / Order on Home to whoever may create them, above the document they become', function () {
    // OPERATOR creates both drafts and the documents: each draft sits just above its document.
    // A delivery note has no "New": it always comes from an order.
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);
    $labels = $this->actingAs($operator)->get(route('home'))->viewData('create')->map(fn ($kind) => $kind->label('new'))->all();

    expect(array_slice($labels, 0, 4))->toBe([
        __('drafts.types.receipt.new'), __('transactions.types.receive.new'),
        __('drafts.types.order.new'), __('transactions.types.transfer.new'),
    ])->and($labels)->not->toContain(__('transactions.types.delivery_note.new'));
    $this->get(route('home'))
        ->assertSee(route('draft-receipts.create'), false)
        ->assertSee(route('orders.create'), false);

    // A role with only draft receipts gets that one, and no order.
    $role = DB::table('role')->insertGetId(['code' => 'BRANCH', 'name' => 'Branch clerk', 'is_system' => false], 'id');
    DB::table('role_permission')->insert(['role_id' => $role, 'permission_id' => DB::table('permission')->where('code', 'draft.in.create')->value('id')]);
    Permissions::flush();
    $clerk = userWithWarehouses($this->stock->warehouse);
    DB::table('user_role')->where('user_id', $clerk->id)->delete();
    DB::table('user_role')->insert(['user_id' => $clerk->id, 'role_id' => $role]);

    $this->actingAs($clerk->fresh())->get(route('home'))
        ->assertSee(route('draft-receipts.create'), false)
        ->assertDontSee(route('orders.create'), false)
        ->assertDontSee(__('home.nothing_to_create'));

    // AUDITOR creates nothing.
    $this->actingAs(userWithRole('AUDITOR', $this->stock->warehouse))->get(route('home'))
        ->assertDontSee(route('draft-receipts.create'), false)
        ->assertSee(__('home.nothing_to_create'));
});

it('is closed to users without the draft permissions', function () {
    $this->actingAs(userWithRole('AUDITOR', $this->stock->warehouse))
        ->get(route('orders'))
        ->assertForbidden();
});

it('keeps a customer user on their own customer and branches', function () {
    $uptown = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'UP', 'name' => 'Uptown']);
    $someoneElse = Customer::factory()->create(['name' => 'Someone Else']);
    $portal = userWithRole('CUSTOMER', $this->stock->warehouse);
    $portal->forceFill(['customer_id' => $this->stock->customer->id])->save();
    $portal->branches()->attach($uptown->id);

    Livewire::actingAs($portal->fresh())
        ->test(DraftForm::class, ['type' => 'OUT'])
        ->assertSet('customerId', $this->stock->customer->id)
        ->assertSet('branchId', $uptown->id)
        ->assertDontSee('Someone Else')
        ->assertDontSee('Downtown')
        ->call('chooseCustomer', $someoneElse->id)
        ->assertSet('customerId', $this->stock->customer->id);
});

it('adds branches in Settings, each code once per customer', function () {
    $other = Customer::factory()->create();

    Livewire::actingAs(userWithRole('MANAGER'))
        ->test(Branches::class)
        ->call('add')
        ->set('form.customer_id', $this->stock->customer->id)
        ->set('form.name', 'Harbour')
        ->set('form.code', 'hb')
        ->set('form.manager_email', 'hb@acme.test')
        ->call('save')
        ->assertHasNoErrors()
        ->call('add')
        ->set('form.customer_id', $this->stock->customer->id)
        ->set('form.code', 'DT')
        ->assertHasErrors(['form.code' => 'unique'])
        ->set('form.customer_id', $other->id)
        ->set('form.name', 'Downtown')
        ->set('form.manager_email', 'dt@other.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(CustomerBranch::where('code', 'HB')->value('customer_id'))->toBe($this->stock->customer->id)
        ->and(CustomerBranch::where('code', 'DT')->count())->toBe(2);
});

it('links a user to a customer and some of its branches in Settings', function () {
    $user = userWithRole('CUSTOMER', $this->stock->warehouse);

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->set('customerId', $this->stock->customer->id)
        ->set('branchIds', [$this->branch->id])
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();

    expect($user->isCustomerUser())->toBeTrue()
        ->and($user->branchIds())->toBe([$this->branch->id]);

    // Back to staff: no customer, no branches.
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->set('customerId', '')
        ->assertSet('branchIds', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->isCustomerUser())->toBeFalse()
        ->and(DB::table('user_customer_branch')->where('user_id', $user->id)->count())->toBe(0);
});

it('prints each item’s storage status on a draft', function () {
    $this->stock->items['BEANS']->update(['storage_status' => 'AMBIENT']);
    $draft = savedDraft($this, 'IN', [1]);

    $this->actingAs($this->stock->user)
        ->get(route('draft-receipts.print', $draft->doc_no))
        ->assertOk()
        ->assertSee(StorageStatuses::label('AMBIENT'));
});

it('shows on the list the draft each document came from, and finds it by that number', function (string $type, string $label) {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $draft = savedDraft($this, $type, [4]);

    Livewire::actingAs($this->stock->user)
        ->test(DraftPage::class, ['type' => $type, 'reference' => $draft->doc_no])
        ->call('confirmDraft')
        ->assertHasNoErrors();

    $fromDraft = DB::table('stock_transaction')->where('draft_id', $draft->id)->value('doc_no');
    $direct = $this->stock->post($type, [$this->stock->line('BEANS', 1)]);

    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => $type])
        ->assertSee(__($label))
        ->assertSee($draft->doc_no)
        ->assertSee($fromDraft)
        ->assertSee($direct->doc_no);

    $list->set('search', mb_strtolower($draft->doc_no))
        ->assertSee($fromDraft)
        ->assertDontSee($direct->doc_no);

    // Types that never come from a draft have no such column.
    Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'DAMAGE'])
        ->assertDontSee(__($label));
})->with([
    'delivery note: its order' => ['OUT', 'drafts.types.order.singular'],
    'receipt: its draft receipt' => ['IN', 'drafts.types.receipt.singular'],
]);

it('asks an order for a delivery date and a priority, and shows them on the list and details', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $today = CarbonImmutable::today()->toDateString();

    $form = draftForm($this, 'OUT')
        // Delivered on the order date at Normal priority unless changed.
        ->assertSet('deliveryDate', $today)
        ->assertSet('priority', 'NORMAL')
        ->assertSee(__('drafts.types.order.delivery_date'))
        ->call('chooseCustomer', $this->stock->customer->id);

    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))
        ->set("lines.$first.qty", '2')
        ->set('deliveryDate', CarbonImmutable::today()->subDay()->toDateString())
        ->assertSee(__('drafts.errors.delivery_date_before'))
        ->set('deliveryDate', CarbonImmutable::today()->addDays(2)->toDateString())
        ->set('priority', 'URGENT')
        ->call('save')
        ->assertHasNoErrors();

    $draft = StockDraft::query()->where('transaction_type', 'OUT')->sole();
    expect($draft->priority)->toBe('URGENT')
        ->and($draft->delivery_date)->toBe(CarbonImmutable::today()->addDays(2)->toDateString());

    $rows = Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'OUT'])->call('loadRows')
        ->assertSee(__('drafts.types.order.delivery_date'))
        ->assertSee(__('drafts.priority.URGENT'))
        ->assertSee(Format::date(CarbonImmutable::today()->addDays(2)));
    expect($rows->viewData('rows')->first())->toMatchArray(['priority' => 'URGENT']);

    $this->actingAs($this->stock->user)->get(route('orders.show', $draft->doc_no))
        ->assertSee(__('drafts.priority.label'))
        ->assertSee(__('drafts.priority.URGENT'));

    // Editing keeps them.
    draftForm($this, 'OUT', $draft->doc_no)
        ->assertSet('priority', 'URGENT')
        ->assertSet('deliveryDate', CarbonImmutable::today()->addDays(2)->toDateString());
});

it('asks a draft receipt for neither a delivery date nor a priority', function () {
    savedDraft($this, 'IN', [1]);

    draftForm($this)->assertDontSee(__('drafts.types.order.delivery_date'))->assertDontSee(__('drafts.priority.label'));
    Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'IN'])->call('loadRows')
        ->assertDontSee(__('drafts.priority.label'));
});
