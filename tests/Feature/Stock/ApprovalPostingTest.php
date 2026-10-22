<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Services\Stock\ApprovalThresholds;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\Exceptions\SelfApproval;
use App\Services\Stock\Exceptions\StaleCount;
use App\Services\Stock\PostingService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->manager = userWithRole('MANAGER', $this->stock->warehouse);
    // 36 Canned Beans to work with.
    $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX')]);
    // Expiry dates of the yogurt, by name.
    $this->expiry = [
        'soon' => now()->addDays(5)->toDateString(),
        'late' => now()->addDays(50)->toDateString(),
        'gone' => now()->subDays(3)->toDateString(),
        'month' => now()->addMonth()->toDateString(),
        'two_months' => now()->addMonths(2)->toDateString(),
    ];
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

function setLimit(string $type, float $qty, string $scope = 'GLOBAL', ?string $scopeId = null): void
{
    DB::table('approval_threshold')->insert(['scope' => $scope, 'scope_id' => $scopeId, 'transaction_type' => $type, 'qty_base' => $qty]);
}

/**
 * @return list<array{direction: string, qty: float}>
 */
function movementsOf(StockTransaction $transaction): array
{
    return DB::table('stock_movement')->where('transaction_id', $transaction->id)->orderBy('seq')->get()
        ->map(fn ($m) => ['direction' => $m->direction, 'qty' => (float) $m->qty_base])->all();
}

function damage(Stock $stock, float $qty, string $code = 'BEANS'): StockTransaction
{
    return $stock->post('DAMAGE', [$stock->line($code, $qty)], reason: 'HANDLING');
}

/**
 * @param  list<DocumentLine>  $lines  counted quantities
 */
function countStock(Stock $stock, array $lines): StockTransaction
{
    return $stock->post('ADJUSTMENT', $lines, reason: 'STOCK_COUNT_VARIANCE');
}

// Thresholds

it('posts at once without any limit configured', function () {
    $damage = damage($this->stock, 30);

    expect($damage->status)->toBe('POSTED')
        ->and(movementsOf($damage))->toBe([['direction' => 'OUT', 'qty' => 30.0]])
        ->and(DB::table('approval')->count())->toBe(0)
        ->and($this->stock->balance('BEANS'))->toBe(6.0);
});

it('needs approval at or above the limit, not below it', function (float $qty, string $status) {
    setLimit('DAMAGE', 10);

    $damage = damage($this->stock, $qty);

    expect($damage->status)->toBe($status)
        ->and(DB::table('approval')->where('transaction_id', $damage->id)->exists())->toBe($status === 'PENDING_APPROVAL');

    if ($status === 'PENDING_APPROVAL') {
        // Nothing moves until it is approved.
        expect(movementsOf($damage))->toBe([])
            ->and($this->stock->balance('BEANS'))->toBe(36.0)
            ->and(DB::table('approval')->where('transaction_id', $damage->id)->value('requested_by'))->toBe($this->stock->requester()->id);
    } else {
        expect($this->stock->balance('BEANS'))->toBe(36.0 - $qty);
    }
})->with([
    'below' => [9.0, 'POSTED'],
    'at' => [10.0, 'PENDING_APPROVAL'],
    'above' => [11.0, 'PENDING_APPROVAL'],
]);

it('sends everything for approval with a limit of 0', function () {
    setLimit('DAMAGE', 0);

    expect(damage($this->stock, 1)->status)->toBe('PENDING_APPROVAL');
});

it('needs approval when any one line reaches its limit', function () {
    setLimit('DAMAGE', 10);

    $damage = $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 2), $this->stock->line('BEANS', 1, 'BOX')], reason: 'HANDLING');

    expect($damage->status)->toBe('PENDING_APPROVAL');
});

it('measures an adjustment by its difference, in base units', function (string $counted, string $status) {
    setLimit('ADJUSTMENT', 6);

    // 36 in stock: 30 is 6 less, 31 is 5 less, 42 is 6 more.
    expect(countStock($this->stock, [$this->stock->line('BEANS', $counted)])->status)->toBe($status);
})->with([
    'just under' => ['31', 'POSTED'],
    'loss at the limit' => ['30', 'PENDING_APPROVAL'],
    'gain at the limit' => ['42', 'PENDING_APPROVAL'],
]);

it('uses the item limit first, then the customer limit, then the global one', function () {
    $thresholds = new ApprovalThresholds;
    $beans = $this->stock->id('BEANS');
    $customer = $this->stock->customer->id;

    expect($thresholds->resolve($beans, $customer, 'DAMAGE'))->toBeNull();

    setLimit('DAMAGE', 10);
    expect((new ApprovalThresholds)->resolve($beans, $customer, 'DAMAGE'))->toBe(10.0);

    setLimit('DAMAGE', 5, 'CUSTOMER', $customer);
    setLimit('DAMAGE', 1, 'CUSTOMER', Customer::factory()->create()->id);
    expect((new ApprovalThresholds)->resolve($beans, $customer, 'DAMAGE'))->toBe(5.0);

    $this->stock->items['BEANS']->update(['approval_threshold_qty' => 3]);
    expect((new ApprovalThresholds)->resolve($beans, $customer, 'DAMAGE'))->toBe(3.0)
        // The customer limit still applies to the customer's other items.
        ->and((new ApprovalThresholds)->resolve($this->stock->id('YOGURT'), $customer, 'DAMAGE'))->toBe(5.0)
        // Limits are per type.
        ->and((new ApprovalThresholds)->resolve($this->stock->id('YOGURT'), $customer, 'ADJUSTMENT'))->toBeNull();

    // And posting follows the same order: 3 for beans (item limit), under the customer's 5.
    expect(damage($this->stock, 3)->status)->toBe('PENDING_APPROVAL')
        ->and(damage($this->stock, 2)->status)->toBe('POSTED');
});

// Approving and rejecting

it('posts the stock when another person approves', function () {
    setLimit('DAMAGE', 10);
    $damage = damage($this->stock, 12);

    app(PostingService::class)->approve($damage, $this->manager);

    $approval = DB::table('approval')->where('transaction_id', $damage->id)->first();
    expect($damage->fresh()->status)->toBe('POSTED')
        ->and($damage->fresh()->getAttribute('posted_at'))->not->toBeNull()
        ->and(movementsOf($damage))->toBe([['direction' => 'OUT', 'qty' => 12.0]])
        ->and($this->stock->balance('BEANS'))->toBe(24.0)
        ->and($approval->decision)->toBe('APPROVED')
        ->and($approval->decided_by)->toBe($this->manager->id)
        ->and(DB::table('audit_log')->where('entity', 'stock_transaction')->where('entity_id', $damage->id)->pluck('action')->all())->toBe(['SUBMIT', 'APPROVE']);
});

it('keeps the expiry date chosen on a damage line while it waits, and takes it from that date when approved', function () {
    setLimit('DAMAGE', 5);
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 10, expiry: $this->expiry['soon']),
        $this->stock->line('YOGURT', 10, expiry: $this->expiry['late']),
    ]);

    $damage = $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 8, expiry: $this->expiry['late'])], reason: 'HANDLING');

    expect($damage->status)->toBe('PENDING_APPROVAL')
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $damage->id)->value('expiry_date'))->toBe($this->expiry['late']);

    app(PostingService::class)->approve($damage, $this->manager);

    expect($this->stock->balance('YOGURT', $this->expiry['late']))->toBe(2.0)
        ->and($this->stock->balance('YOGURT', $this->expiry['soon']))->toBe(10.0);
});

it('refuses to approve a damage when its chosen expiry date no longer has enough', function () {
    setLimit('DAMAGE', 5);
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 10, expiry: $this->expiry['soon']),
        $this->stock->line('YOGURT', 10, expiry: $this->expiry['late']),
    ]);
    $damage = $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 8, expiry: $this->expiry['late'])], reason: 'HANDLING');

    // Meanwhile most of LATE is issued.
    $this->stock->post('OUT', [$this->stock->line('YOGURT', 5, expiry: $this->expiry['late'])]);

    expect(fn () => app(PostingService::class)->approve($damage, $this->manager))->toThrow(InsufficientStock::class);
    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL')
        ->and($this->stock->balance('YOGURT', $this->expiry['late']))->toBe(5.0)
        ->and($this->stock->balance('YOGURT', $this->expiry['soon']))->toBe(10.0);
});

it('writes off chosen expired stock on a damage, which an issue may not take', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->expiry['gone'])]);

    $damage = $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 6, expiry: $this->expiry['gone'])], reason: 'EXPIRED');

    expect($damage->status)->toBe('POSTED')
        ->and($this->stock->balance('YOGURT', $this->expiry['gone']))->toBe(0.0);
});

it('posts an approved count as IN and OUT movements to the counted quantities', function () {
    setLimit('ADJUSTMENT', 1);
    $this->stock->post('IN', [$this->stock->line('YOGURT', 12, expiry: $this->expiry['month'])]);

    $adjustment = countStock($this->stock, [
        $this->stock->line('BEANS', '2', 'BOX'),               // 36 → 24: OUT 12
        $this->stock->line('YOGURT', '15', expiry: $this->expiry['month']),    // 12 → 15: IN 3
    ]);

    expect($adjustment->status)->toBe('PENDING_APPROVAL')
        ->and($this->stock->balance('BEANS'))->toBe(36.0);

    app(PostingService::class)->approve($adjustment, $this->manager);

    expect(movementsOf($adjustment))->toBe([['direction' => 'OUT', 'qty' => 12.0], ['direction' => 'IN', 'qty' => 3.0]])
        ->and($this->stock->balance('BEANS'))->toBe(24.0)
        ->and($this->stock->balance('YOGURT', $this->expiry['month']))->toBe(15.0);
});

it('refuses self-approval in the service, the policy and the database', function () {
    setLimit('DAMAGE', 10);
    $damage = damage($this->stock, 12, 'BEANS');

    // The creator had no approve permission when sending it; being made a manager
    // afterwards doesn't let them decide on their own pending document.
    $this->stock->requester()->roles()->attach(Role::where('code', 'MANAGER')->firstOrFail());
    $creator = $this->stock->requester()->fresh();

    expect($creator->hasPermission('stock.damage.approve'))->toBeTrue()
        ->and(Gate::forUser($creator)->inspect('decide', $damage)->message())->toBe(__('stock.errors.own_document'))
        ->and(Gate::forUser($this->manager)->allows('decide', $damage))->toBeTrue()
        ->and(fn () => app(PostingService::class)->approve($damage, $creator))
        ->toThrow(SelfApproval::class, __('stock.errors.own_document'))
        ->and(fn () => app(PostingService::class)->reject($damage, 'No', $creator))
        ->toThrow(SelfApproval::class, __('stock.errors.own_document'));

    // In a savepoint, so the refused statement doesn't abort the test's transaction.
    expect(fn () => DB::transaction(fn () => DB::table('approval')->where('transaction_id', $damage->id)->update([
        'decided_by' => $creator->id, 'decision' => 'APPROVED', 'decided_at' => now(),
    ])))->toThrow(QueryException::class, 'chk_approver_not_requester');

    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL')
        ->and($this->stock->balance('BEANS'))->toBe(36.0);
});

it('posts an approver’s own document over the limit at once, without a second approval', function () {
    setLimit('DAMAGE', 10);
    setLimit('ADJUSTMENT', 1);

    // A manager holds stock.damage.approve and stock.adjustment.approve.
    $damage = $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 12)], $this->manager, reason: 'HANDLING');
    $count = $this->stock->post('ADJUSTMENT', [$this->stock->line('BEANS', '20')], $this->manager, reason: 'STOCK_COUNT_VARIANCE');

    expect($damage->status)->toBe('POSTED')
        ->and($count->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(20.0)
        // No approval request is opened (nobody else has to decide)...
        ->and(DB::table('approval')->whereIn('transaction_id', [$damage->id, $count->id])->count())->toBe(0)
        // ...and the audit log says it was the creator's own approval.
        ->and(json_decode((string) DB::table('audit_log')->where('entity', 'stock_transaction')->where('entity_id', $damage->id)->where('action', 'POST')->value('after_data'), true))
        ->toMatchArray(['status' => 'POSTED', 'self_approved' => true])
        ->and(Stock::ledgerMismatches())->toBe([]);

    // Negative stock is still blocked for them.
    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 50)], $this->manager, reason: 'HANDLING'))
        ->toThrow(InsufficientStock::class);

    // Someone without the permission still waits for approval.
    expect($this->stock->post('DAMAGE', [$this->stock->line('BEANS', 12)], reason: 'HANDLING')->status)->toBe('PENDING_APPROVAL');
});

it('does not let someone without the approve permission decide', function () {
    setLimit('DAMAGE', 10);
    $damage = damage($this->stock, 12);
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);

    expect(Gate::forUser($operator)->inspect('decide', $damage)->message())->toBe(__('stock.errors.cannot_approve'));
});

it('rejects with a reason and never touches stock', function () {
    setLimit('ADJUSTMENT', 1);
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '0')]);

    expect(fn () => app(PostingService::class)->reject($adjustment, '  ', $this->manager))
        ->toThrow(PostingRejected::class, __('stock.errors.reject_reason_required'));

    app(PostingService::class)->reject($adjustment, 'Count again with two people', $this->manager);

    $approval = DB::table('approval')->where('transaction_id', $adjustment->id)->first();
    expect($adjustment->fresh()->status)->toBe('REJECTED')
        ->and($approval->decision)->toBe('REJECTED')
        ->and($approval->reason)->toBe('Count again with two people')
        ->and(movementsOf($adjustment))->toBe([])
        ->and($this->stock->balance('BEANS'))->toBe(36.0);

    // Decided once and for all.
    expect(fn () => app(PostingService::class)->approve($adjustment, $this->manager))
        ->toThrow(AlreadyProcessed::class, 'already rejected by '.$this->manager->full_name);
});

it('approves only once', function () {
    setLimit('DAMAGE', 10);
    $damage = damage($this->stock, 12);

    app(PostingService::class)->approve($damage, $this->manager);

    expect(fn () => app(PostingService::class)->approve($damage, userWithRole('MANAGER', $this->stock->warehouse)))
        ->toThrow(AlreadyProcessed::class, 'already approved by '.$this->manager->full_name)
        ->and($this->stock->balance('BEANS'))->toBe(24.0);
});

it('refuses to approve a count when the stock changed since', function () {
    setLimit('ADJUSTMENT', 1);
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '30')]);

    // Someone issues 5 meanwhile: the count no longer says how much is missing.
    $this->stock->post('OUT', [$this->stock->line('BEANS', 5)]);

    expect(fn () => app(PostingService::class)->approve($adjustment, $this->manager))
        ->toThrow(StaleCount::class, __('stock.errors.count_changed', ['item' => 'Canned Beans']));

    expect($adjustment->fresh()->status)->toBe('PENDING_APPROVAL')
        ->and(movementsOf($adjustment))->toBe([])
        ->and($this->stock->balance('BEANS'))->toBe(31.0);

    // What to do instead: reject it and count again.
    app(PostingService::class)->reject($adjustment, 'Stock changed, count again', $this->manager);
    expect($adjustment->fresh()->status)->toBe('REJECTED');
});

it('never lets a loss drive a balance negative', function () {
    setLimit('DAMAGE', 10);
    $damage = damage($this->stock, 30);

    // Most of the stock goes out before the damage is approved.
    $this->stock->post('OUT', [$this->stock->line('BEANS', 20)]);

    expect(fn () => app(PostingService::class)->approve($damage, $this->manager))
        ->toThrow(InsufficientStock::class);

    expect($damage->fresh()->status)->toBe('PENDING_APPROVAL')
        ->and($this->stock->balance('BEANS'))->toBe(16.0);

    // A count can't be negative, and can't take more than there is: 0 is the floor.
    expect(fn () => countStock($this->stock, [$this->stock->line('BEANS', '-1')]))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.not_negative'));

    // And damage can't take more than is in stock.
    expect(fn () => damage($this->stock, 17))->toThrow(InsufficientStock::class);
});

// Adjustment lines

it('stores what was counted, what the system had, the direction and the difference', function () {
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '2', 'BOX')]);
    $line = DB::table('stock_transaction_line as l')->join('unit as u', 'u.id', '=', 'l.unit_id')->where('transaction_id', $adjustment->id)->first(['l.*', 'u.code']);

    expect($adjustment->status)->toBe('POSTED')
        ->and($adjustment->getAttribute('reason_code'))->toBe('STOCK_COUNT_VARIANCE')
        ->and((float) $line->qty_system)->toBe(36.0)
        ->and((float) $line->qty_counted)->toBe(24.0)
        ->and($line->direction)->toBe('OUT')
        ->and((float) $line->qty_base)->toBe(12.0)
        // 36, 24 and 12 are all whole boxes, so the line keeps the box.
        ->and($line->code)->toBe('BOX')
        ->and((float) $line->qty_entered)->toBe(1.0)
        ->and($this->stock->balance('BEANS'))->toBe(24.0);
});

it('keeps a count in the base unit when it is not whole in the unit counted', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 1)]); // 37 = 3 Box + 1 Piece
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '3', 'BOX')]);
    $line = DB::table('stock_transaction_line as l')->join('unit as u', 'u.id', '=', 'l.unit_id')->where('transaction_id', $adjustment->id)->first(['l.*', 'u.code']);

    expect($line->code)->toBe('PC')
        ->and((float) $line->qty_entered)->toBe(1.0)
        ->and((float) $line->qty_counted)->toBe(36.0);
});

it('counts 0: everything in the system goes out', function () {
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '0')]);

    expect(movementsOf($adjustment))->toBe([['direction' => 'OUT', 'qty' => 36.0]])
        ->and($this->stock->balance('BEANS'))->toBe(0.0);
});

it('saves only the lines that differ', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->expiry['month'])]);
    $adjustment = countStock($this->stock, [$this->stock->line('BEANS', '36'), $this->stock->line('YOGURT', '4', expiry: $this->expiry['month'])]);

    expect(DB::table('stock_transaction_line')->where('transaction_id', $adjustment->id)->count())->toBe(1)
        ->and($this->stock->balance('YOGURT', $this->expiry['month']))->toBe(4.0);

    expect(fn () => countStock($this->stock, [$this->stock->line('BEANS', '36')]))
        ->toThrow(PostingRejected::class, __('stock.errors.nothing_to_adjust'));
});

it('counts an expiry item per expiry date, and the same date only once', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->expiry['month']),
        $this->stock->line('YOGURT', 6, expiry: $this->expiry['two_months']),
    ]);

    expect(fn () => countStock($this->stock, [$this->stock->line('YOGURT', '5')]))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.expiry_choose'));

    expect(fn () => countStock($this->stock, [$this->stock->line('YOGURT', '5', expiry: $this->expiry['month']), $this->stock->line('YOGURT', '4', expiry: $this->expiry['month'])]))
        ->toThrow(DuplicateLine::class, __('transactions.form.duplicate.count_expiry', ['item' => 'Yogurt', 'expiry' => Format::date(CarbonImmutable::parse($this->expiry['month'])), 'row' => '1']));

    countStock($this->stock, [$this->stock->line('YOGURT', '5', expiry: $this->expiry['month']), $this->stock->line('YOGURT', '8', expiry: $this->expiry['two_months'])]);

    expect($this->stock->balance('YOGURT', $this->expiry['month']))->toBe(5.0)
        ->and($this->stock->balance('YOGURT', $this->expiry['two_months']))->toBe(8.0);
});

it('counts serial items by the serials found; the others leave stock', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 3, serials: ['SN-1', 'SN-2', 'SN-3'])]);

    countStock($this->stock, [$this->stock->line('PHONE', '1', serials: ['SN-2'])]);

    expect($this->stock->balance('PHONE'))->toBe(1.0)
        ->and(DB::table('serial_number')->orderBy('serial_no')->pluck('status', 'serial_no')->all())
        ->toBe(['SN-1' => 'DAMAGED', 'SN-2' => 'IN_STOCK', 'SN-3' => 'DAMAGED']);
});

it('needs a reason from the type’s list', function (?string $reason) {
    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 1)], reason: $reason))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.reason_required'));
})->with([null, 'STOCK_COUNT_VARIANCE']);

// Damage lines

it('writes off expired stock first', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->expiry['gone']),
        $this->stock->line('YOGURT', 10, expiry: $this->expiry['month']),
    ]);

    damage($this->stock, 6, 'YOGURT');

    expect($this->stock->balance('YOGURT', $this->expiry['gone']))->toBe(0.0)
        ->and($this->stock->balance('YOGURT', $this->expiry['month']))->toBe(8.0);
});

it('damages the serials picked, after approval', function () {
    setLimit('DAMAGE', 1);
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);

    $damage = $this->stock->post('DAMAGE', [$this->stock->line('PHONE', 1, serials: ['SN-2'])], reason: 'HANDLING');
    expect(DB::table('serial_number')->where('serial_no', 'SN-2')->value('status'))->toBe('IN_STOCK');

    app(PostingService::class)->approve($damage, $this->manager);

    expect(DB::table('serial_number')->where('serial_no', 'SN-2')->value('status'))->toBe('DAMAGED')
        ->and($this->stock->balance('PHONE'))->toBe(1.0);
});

it('can’t cancel a damage or an adjustment', function () {
    $damage = damage($this->stock, 1);

    expect(fn () => app(PostingService::class)->cancel($damage, $this->manager, 'Entered by mistake'))
        ->toThrow(PostingRejected::class, __('stock.errors.cannot_cancel_status'));
});
