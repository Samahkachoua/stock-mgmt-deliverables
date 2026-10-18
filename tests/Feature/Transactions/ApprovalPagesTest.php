<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\DecideApproval;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\PostingService;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->operator = userWithRole('OPERATOR', $this->stock->warehouse);
    $this->manager = userWithRole('MANAGER', $this->stock->warehouse);
    $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX'), $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);
    session([EnsureCurrentWarehouse::SESSION_KEY => $this->stock->warehouse->id]);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

function approvalForm(Stock $stock, User $user, string $type, string $reason): Testable
{
    return Livewire::actingAs($user)->test(TransactionForm::class, ['type' => $type])
        ->call('chooseCustomer', $stock->customer->id)
        ->set('reason', $reason);
}

function pendingDamage(Stock $stock, User $by, float $qty = 12): StockTransaction
{
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);

    return $stock->post('DAMAGE', [$stock->line('BEANS', $qty)], $by, reason: 'HANDLING');
}

/**
 * Whether Save is disabled (the boolean attribute, not a disabled: class).
 */
function countSaveDisabled(Testable $form): bool
{
    preg_match('/<button\s[^>]*type="submit"[^>]*>/s', $form->html(), $match);

    expect($match)->not->toBeEmpty();

    return (bool) preg_match('/\sdisabled(?=[\s>\/])/', preg_replace('/"[^"]*"/', '""', $match[0]));
}

// New Damage / Adjustment

it('saves a damage under the limit at once, and sends one at the limit for approval', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);
    $form = approvalForm($this->stock, $this->operator, 'DAMAGE', 'HANDLING');
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '9')
        ->assertDontSee(__('transactions.form.approval_notice'))
        ->set("lines.$row.qty", '10')
        ->assertSee(__('transactions.form.approval_notice'))
        ->assertSee(__('transactions.form.send_for_approval'))
        ->call('save')
        ->assertHasNoErrors();

    $damage = StockTransaction::query()->where('transaction_type', 'DAMAGE')->sole();
    $form->assertRedirect(route('damage.show', $damage->doc_no));

    expect($damage->status)->toBe('PENDING_APPROVAL')
        ->and(session('toasts'))->toBe([[
            'type' => 'warning',
            'message' => __('feedback.sent_for_approval', ['type' => __('transactions.types.damage.singular'), 'reference' => $damage->doc_no]),
            'url' => route('damage.show', $damage->doc_no),
            'link' => __('feedback.view'),
        ]])
        ->and($this->stock->balance('BEANS'))->toBe(36.0);
});

it('uses the customer limit in the form before the global one', function () {
    DB::table('approval_threshold')->insert([
        ['scope' => 'GLOBAL', 'scope_id' => null, 'transaction_type' => 'DAMAGE', 'qty_base' => 100],
        ['scope' => 'CUSTOMER', 'scope_id' => $this->stock->customer->id, 'transaction_type' => 'DAMAGE', 'qty_base' => 5],
    ]);
    $form = approvalForm($this->stock, $this->operator, 'DAMAGE', 'HANDLING');
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '5')
        ->assertSee(__('transactions.form.send_for_approval'));
});

it('saves a count: lines that match are left out, 0 is a count', function () {
    $form = approvalForm($this->stock, $this->operator, 'ADJUSTMENT', 'STOCK_COUNT_VARIANCE');
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '36')
        ->assertSee(__('stock.errors.nothing_to_adjust'));
    expect(countSaveDisabled($form))->toBeTrue();

    $form->set("lines.$row.qty", '0')->assertSee('-36');
    expect(countSaveDisabled($form))->toBeFalse();

    // Final once saved: Save asks first.
    $form->call('save')->assertNotSet('confirm', null)->call('runConfirmed')->assertHasNoErrors();

    expect(StockTransaction::query()->where('transaction_type', 'ADJUSTMENT')->sole()->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(0.0);
});

it('refuses to count the same item twice', function () {
    $form = approvalForm($this->stock, $this->operator, 'ADJUSTMENT', 'STOCK_COUNT_VARIANCE');
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.qty", '30');
    $second = array_keys($form->get('lines'))[1];
    $form->set("lines.$second.item", $this->stock->id('BEANS'))->set("lines.$second.qty", '31');

    $form->assertSee(__('transactions.form.errors.counted_twice'));
    expect(countSaveDisabled($form))->toBeTrue();
});

it('counts serial items by unticking the pieces not found', function () {
    $form = approvalForm($this->stock, $this->operator, 'ADJUSTMENT', 'LOSS_THEFT');
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('PHONE'))
        ->assertSet("lines.$row.picked", ['SN-1', 'SN-2'])
        ->assertSet("lines.$row.qty", '2')
        ->assertSee(__('transactions.form.serials_found_hint'))
        ->set("lines.$row.picked", ['SN-2'])
        ->assertSet("lines.$row.qty", '1')
        ->assertSee('-1')
        ->call('save')
        ->call('runConfirmed')
        ->assertHasNoErrors();

    expect($this->stock->balance('PHONE'))->toBe(1.0)
        ->and(DB::table('serial_number')->where('serial_no', 'SN-1')->value('status'))->toBe('DAMAGED');
});

// Details: approve / reject

it('lets a manager approve from the details page', function () {
    $damage = pendingDamage($this->stock, $this->operator);

    $this->actingAs($this->manager)->get(route('damage.show', $damage->doc_no))
        ->assertOk()
        ->assertSee(__('transactions.status.pending_approval'))
        ->assertSeeLivewire(DecideApproval::class)
        ->assertSee(__('transactions.details.approve'))
        ->assertSee(__('transactions.activity.sent_for_approval', ['name' => '']), false)
        ->assertDontSeeLivewire(CancelTransaction::class);

    Livewire::actingAs($this->manager)->test(DecideApproval::class, ['transactionId' => $damage->id])
        ->call('approve')
        ->assertRedirect(route('damage.show', $damage->doc_no));

    expect($damage->fresh()->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(24.0);

    $this->actingAs($this->manager)->get(route('damage.show', $damage->doc_no))
        ->assertSee(__('stock.approval.approved_by'))
        ->assertSee($this->manager->full_name)
        ->assertDontSeeLivewire(DecideApproval::class)
        // Damage and Adjustment can't be cancelled once posted.
        ->assertDontSeeLivewire(CancelTransaction::class);
});

it('asks for the rejection reason in the confirmation modal and shows it afterwards', function () {
    $damage = pendingDamage($this->stock, $this->operator);

    $decide = Livewire::actingAs($this->manager)->test(DecideApproval::class, ['transactionId' => $damage->id])
        ->call('startReject')
        ->assertSee(__('confirm.reject.reason'))
        ->call('runConfirmed')
        ->assertHasErrors(['confirmReason'])
        ->assertSee(__('confirm.reason_required'));

    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL');

    $decide->set('confirmReason', 'Photos show the boxes are fine')->call('runConfirmed')->assertRedirect(route('damage.show', $damage->doc_no));

    expect($damage->fresh()->status)->toBe('REJECTED')
        ->and($this->stock->balance('BEANS'))->toBe(36.0);

    $this->actingAs($this->operator)->get(route('damage.show', $damage->doc_no))
        ->assertSee(__('transactions.status.rejected'))
        ->assertSee(__('stock.approval.rejection_reason'))
        ->assertSee('Photos show the boxes are fine');
});

it('says when the stock changed since the count', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'ADJUSTMENT', 'qty_base' => 1]);
    $adjustment = $this->stock->post('ADJUSTMENT', [$this->stock->line('BEANS', '30')], $this->operator, reason: 'STOCK_COUNT_VARIANCE');
    $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]);

    Livewire::actingAs($this->manager)->test(DecideApproval::class, ['transactionId' => $adjustment->id])
        ->call('approve')
        ->assertSet('problem', __('stock.errors.count_changed', ['item' => 'Canned Beans']))
        ->assertNoRedirect();

    expect($adjustment->fresh()->status)->toBe('PENDING_APPROVAL');
});

it('does not let the creator approve their own pending document, even once a manager', function () {
    $damage = pendingDamage($this->stock, $this->operator);

    // Made a manager after sending it for approval.
    $this->operator->roles()->attach(Role::where('code', 'MANAGER')->firstOrFail());
    $creator = $this->operator->fresh();

    $this->actingAs($creator)->get(route('damage.show', $damage->doc_no))
        ->assertSee(__('stock.approval.own'))
        ->assertDontSee('wire:click="approve"', false);

    Livewire::actingAs($creator)->test(DecideApproval::class, ['transactionId' => $damage->id])
        ->call('approve')
        ->assertForbidden();

    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL');
});

it('saves an approver’s own damage over the limit at once, with no approval notice', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);

    $form = approvalForm($this->stock, $this->manager, 'DAMAGE', 'HANDLING');
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '12');

    expect($form->html())->not->toContain(e(__('transactions.form.approval_notice')))
        ->not->toContain(e(__('transactions.form.send_for_approval')));

    // Posted at once, so final: Save asks first.
    $form->call('save')->assertNotSet('confirm', null)->call('runConfirmed')->assertHasNoErrors();
    $damage = StockTransaction::query()->where('transaction_type', 'DAMAGE')->sole();
    $form->assertRedirect(route('damage.show', $damage->doc_no));

    expect($damage->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(24.0)
        ->and(session('toasts')[0]['type'])->toBe('success');
});

it('shows people who can’t approve that it is waiting', function () {
    $damage = pendingDamage($this->stock, $this->operator);

    $this->actingAs($this->operator)->get(route('damage.show', $damage->doc_no))
        ->assertSee(__('stock.approval.waiting'))
        ->assertDontSeeLivewire(DecideApproval::class);

    Livewire::actingAs($this->operator)->test(DecideApproval::class, ['transactionId' => $damage->id])
        ->call('approve')
        ->assertForbidden();
});

it('shows a pending adjustment in Arabic', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'ADJUSTMENT', 'qty_base' => 1]);
    $adjustment = $this->stock->post('ADJUSTMENT', [$this->stock->line('BEANS', '30')], $this->operator, reason: 'STOCK_COUNT_VARIANCE');
    $this->manager->update(['preferred_lang' => 'ar']);

    $this->actingAs($this->manager)->get(route('adjustment.show', $adjustment->doc_no))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('transactions.details.approve', [], 'ar'))
        ->assertSee(__('transactions.lines.in_system', [], 'ar'))
        ->assertSee(__('transactions.reasons.STOCK_COUNT_VARIANCE', [], 'ar'));
});

// Sidebar and list

it('counts documents waiting for the user’s approval in the sidebar', function () {
    pendingDamage($this->stock, $this->operator);
    $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 11)], $this->operator, reason: 'TRANSPORT');
    // The manager's own document can't be approved by them, so it isn't counted.
    $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 10)], $this->manager, reason: 'TRANSPORT');

    $this->actingAs($this->manager)->get(route('issue'))
        ->assertSee(__('nav.pending_count', ['count' => '2']));

    $this->actingAs($this->operator)->get(route('issue'))
        ->assertDontSee(__('nav.pending_count', ['count' => '2']))
        ->assertDontSee(__('nav.pending_count', ['count' => '3']));

    // Approving takes it off the count.
    app(PostingService::class)->approve(StockTransaction::query()->where('created_by', $this->operator->id)->firstOrFail(), $this->manager);
    $this->actingAs($this->manager)->get(route('issue'))->assertSee(__('nav.pending_count', ['count' => '1']));
});

it('lists pending documents first for approvers', function () {
    $pending = pendingDamage($this->stock, $this->operator);
    $later = $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 1)], $this->operator, reason: 'HANDLING');
    // now() is the same for the whole test transaction: make the pending one older.
    DB::table('stock_transaction')->where('id', $pending->id)->update(['created_at' => now()->subHour()]);

    $manager = Livewire::actingAs($this->manager)->test(TransactionList::class, ['type' => 'DAMAGE'])
        ->assertSee(__('transactions.filters.pending_first'));
    expect(strpos($manager->html(), $pending->doc_no))->toBeLessThan(strpos($manager->html(), $later->doc_no));

    // Others see the newest first.
    $operator = Livewire::actingAs($this->operator)->test(TransactionList::class, ['type' => 'DAMAGE'])
        ->assertDontSee(__('transactions.filters.pending_first'));
    expect(strpos($operator->html(), $later->doc_no))->toBeLessThan(strpos($operator->html(), $pending->doc_no));
});
