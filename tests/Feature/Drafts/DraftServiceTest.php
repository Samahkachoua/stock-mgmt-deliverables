<?php

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\StockDraft;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\BatchDateMismatch;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\PostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->depot = Warehouse::factory()->create(['code' => 'DPX', 'name' => 'Depot X']);
    $this->stock->user->warehouses()->attach($this->depot->id);
    $this->branch = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);
    $this->drafts = app(DraftService::class);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

function draftLine(Stock $stock, string $code, int|string $qty, string $unit = 'PC', ?string $expiry = null, array $serials = [], ?string $batch = null): DraftLineInput
{
    return new DraftLineInput($stock->id($code), $unit, $qty, $expiry, $serials, $batch);
}

/**
 * A draft in $warehouse (the Stock helper's own warehouse by default).
 *
 * @param  list<DraftLineInput>  $lines
 */
function draftOf(object $test, string $type, array $lines, ?string $requestId = null, ?string $version = null, ?CustomerBranch $branch = null, ?Warehouse $warehouse = null): DraftInput
{
    $keyed = [];
    foreach ($lines as $i => $line) {
        // A draft receipt line of an expiry item without a batch gets the date's own (Stock::batch()).
        $keyed['r'.($i + 1)] = $type === 'IN' && $line->batch === null && $line->expiry !== null
            ? new DraftLineInput($line->productId, $line->unit, $line->qty, $line->expiry, $line->serials, Stock::batch($line->expiry))
            : $line;
    }

    return new DraftInput($type, $test->stock->customer->id, ($branch ?? $test->branch)->id, ($warehouse ?? $test->stock->warehouse)->id, CarbonImmutable::today(), 'PO 77', $keyed, $requestId, $version, deliveryDate: CarbonImmutable::today(), priority: 'NORMAL');
}

function stockCounts(): array
{
    return [
        'transactions' => DB::table('stock_transaction')->count(),
        'movements' => DB::table('stock_movement')->count(),
        'balance' => (float) DB::table('stock_balance')->sum('qty_base'),
    ];
}

function version(StockDraft $draft): string
{
    return (string) DB::table('stock_draft')->where('id', $draft->id)->value('updated_at');
}

it('saves and edits a draft receipt without touching stock', function () {
    $before = stockCounts();

    $draft = $this->drafts->save(draftOf($this, 'IN', [
        draftLine($this->stock, 'BEANS', 2, 'BOX'),
        draftLine($this->stock, 'BEANS', 5),
    ]), $this->stock->user);

    expect($draft->warehouse_id)->toBe($this->stock->warehouse->id);

    // Edited: another warehouse, one line.
    $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 7)], version: version($draft), warehouse: $this->depot), $this->stock->user, $draft);

    $lines = DB::table('stock_draft_line')->where('draft_id', $draft->id)->get();

    expect($draft->doc_no)->toMatch('/^DRR-\d{4}-\d{6}$/')
        ->and($draft->status)->toBe('DRAFT')
        ->and($draft->fresh()->warehouse_id)->toBe($this->depot->id)
        ->and($lines)->toHaveCount(1)
        ->and((float) $lines[0]->qty_base)->toBe(7.0)
        ->and(stockCounts())->toBe($before)
        ->and(DB::table('audit_log')->where('entity', 'stock_draft')->where('entity_id', $draft->id)->pluck('action')->all())->toBe(['CREATE', 'UPDATE']);
});

it('confirms a draft receipt as one receipt in its warehouse, linked to the draft', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [
        draftLine($this->stock, 'BEANS', 2, 'BOX'),
        draftLine($this->stock, 'BEANS', 10),
        draftLine($this->stock, 'YOGURT', 6, expiry: now()->addMonth()->toDateString()),
        draftLine($this->stock, 'YOGURT', 1, 'BOX', now()->addMonths(2)->toDateString()),
        draftLine($this->stock, 'PHONE', 2, serials: ['SN-1', 'SN-2']),
    ], warehouse: $this->depot), $this->stock->user);

    // Its creator may confirm it.
    $documents = $this->drafts->confirm($draft, $this->stock->user);

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->warehouse_id)->toBe($this->depot->id)
        ->and($documents[0]->status)->toBe('POSTED')
        ->and($documents[0]->transaction_type)->toBe('IN')
        ->and(DB::table('stock_transaction')->where('draft_id', $draft->id)->where('customer_branch_id', $this->branch->id)->count())->toBe(1)
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $documents[0]->id)->count())->toBe(5)
        ->and($this->stock->balance('BEANS', warehouse: $this->depot))->toBe(34.0)
        ->and($this->stock->balance('YOGURT', warehouse: $this->depot))->toBe(12.0)
        ->and($this->stock->balance('BEANS'))->toBe(0.0)
        ->and(DB::table('serial_number')->where('warehouse_id', $this->depot->id)->where('status', 'IN_STOCK')->count())->toBe(2)
        ->and($draft->fresh()->status)->toBe('POSTED')
        ->and($draft->fresh()->confirmed_by)->toBe($this->stock->user->id);
});

it('confirms a order from the expiry dates picked on it', function () {
    $soon = now()->addDays(10)->toDateString();
    $later = now()->addDays(40)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon), $this->stock->line('YOGURT', 6, expiry: $later)]);

    $draft = $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'YOGURT', 4, expiry: $later)]), $this->stock->user);
    $documents = $this->drafts->confirm($draft, $this->stock->user);

    // Confirmed: a delivery note holding the order's stock; nothing has left yet.
    expect($documents)->toHaveCount(1)
        ->and($documents[0]->transaction_type)->toBe('OUT')
        ->and($documents[0]->status)->toBe('CONFIRMED')
        ->and($documents[0]->warehouse_id)->toBe($this->stock->warehouse->id)
        ->and($this->stock->balance('YOGURT', $later))->toBe(6.0)
        ->and($this->stock->reserved('YOGURT', $later))->toBe(4.0);

    app(PostingService::class)->dispatchDelivery($documents[0], null, null, null, $this->stock->user);
    app(PostingService::class)->deliver($documents[0], Stock::deliveredInFull($documents[0]), $this->stock->user);

    expect($documents[0]->fresh()->status)->toBe('POSTED')
        ->and($this->stock->balance('YOGURT', $soon))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $later))->toBe(2.0)
        ->and($this->stock->reserved('YOGURT'))->toBe(0.0);
});

it('reserves an order\'s stock, so a second order can\'t take it, and says which line', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 20)]);
    $this->stock->post('IN', [$this->stock->line('BEANS', 20)], warehouse: $this->depot);

    $this->drafts->save(draftOf($this, 'OUT', [
        draftLine($this->stock, 'BEANS', 5),
        draftLine($this->stock, 'BEANS', 1, 'BOX'),
    ], warehouse: $this->depot), $this->stock->user);
    $before = stockCounts();

    // 17 of 20 reserved: 3 left for anyone else in the depot.
    expect(fn () => $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'BEANS', 2), draftLine($this->stock, 'BEANS', 2, 'BOX')], warehouse: $this->depot), $this->stock->user))
        ->toThrow(fn (InsufficientStock $e) => expect($e->row)->toBe('r2')->and($e->available)->toBe(1.0));

    // The stock in the other warehouse is not used instead; nothing moved.
    expect(stockCounts())->toBe($before)
        ->and(DB::table('stock_draft')->count())->toBe(1)
        ->and($this->stock->reserved('BEANS', warehouse: $this->depot))->toBe(17.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->depot))->toBe(20.0)
        ->and($this->stock->reserved('BEANS'))->toBe(0.0);
});

it('refuses a draft in a warehouse the user does not work in', function () {
    $elsewhere = Warehouse::factory()->create(['code' => 'ELS', 'name' => 'Elsewhere']);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], warehouse: $elsewhere), $this->stock->user))
        ->toThrow(fn (NotAllowed $e) => expect($e->field)->toBe('warehouse'));

    expect(DB::table('stock_draft')->count())->toBe(0);
});

it('refuses a order for more than is in stock when saving', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);

    expect(fn () => $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'BEANS', 4)]), $this->stock->user))
        ->toThrow(fn (InsufficientStock $e) => expect($e->row)->toBe('r1'));

    expect(DB::table('stock_draft')->count())->toBe(0);
});

it('lets the confirmer change quantities and confirm in one step, or keeps nothing when that fails', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $draft = $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'BEANS', 4)]), $this->stock->user);

    // Too much: nothing is kept, not even the change.
    expect(fn () => $this->drafts->confirm($draft, $this->stock->user, draftOf($this, 'OUT', [draftLine($this->stock, 'BEANS', 11)], version: version($draft))))
        ->toThrow(InsufficientStock::class);
    expect((float) DB::table('stock_draft_line')->where('draft_id', $draft->id)->value('qty_base'))->toBe(4.0);

    [$deliveryNote] = $this->drafts->confirm($draft, $this->stock->user, draftOf($this, 'OUT', [draftLine($this->stock, 'BEANS', 3)], version: version($draft)));

    $update = DB::table('audit_log')->where('entity', 'stock_draft')->where('action', 'UPDATE')->first();

    expect((float) DB::table('stock_transaction_line')->where('transaction_id', $deliveryNote->id)->value('qty_base'))->toBe(3.0)
        ->and($this->stock->balance('BEANS'))->toBe(10.0)
        ->and($this->stock->reserved('BEANS'))->toBe(3.0)
        ->and(json_decode($update->before_data, true)['lines'][0]['qty'])->toBe(4)
        ->and(json_decode($update->after_data, true)['lines'][0]['qty'])->toBe(3);
});

it('needs the confirm permission and the draft\'s warehouse to confirm it', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], warehouse: $this->depot), $this->stock->user);

    $operator = userWithRole('OPERATOR', $this->stock->warehouse, $this->depot);
    $managerElsewhere = userWithRole('MANAGER', $this->stock->warehouse);

    expect(fn () => $this->drafts->confirm($draft, $operator))->toThrow(NotAllowed::class)
        ->and(fn () => $this->drafts->confirm($draft, $managerElsewhere))->toThrow(NotAllowed::class, 'Depot X');

    expect($draft->fresh()->status)->toBe('DRAFT')
        ->and($this->drafts->confirm($draft, userWithRole('MANAGER', $this->depot)))->toHaveCount(1);
});

it('confirms a draft once, and no longer edits or cancels it afterwards', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)]), $this->stock->user);
    $version = version($draft);
    $this->drafts->confirm($draft, $this->stock->user);

    expect(fn () => $this->drafts->confirm($draft, $this->stock->user))->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 2)], version: $version), $this->stock->user, $draft))->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->cancel($draft, $this->stock->user, 'wrong'))->toThrow(AlreadyProcessed::class)
        ->and(DB::table('stock_transaction')->where('draft_id', $draft->id)->count())->toBe(1);
});

it('does not overwrite or confirm a draft someone changed since it was opened', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)]), $this->stock->user);
    $opened = version($draft);

    $this->travel(1)->seconds();
    $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 2)], version: $opened), $this->stock->user, $draft);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 3)], version: $opened), $this->stock->user, $draft))
        ->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->confirm($draft, $this->stock->user, version: $opened))->toThrow(AlreadyProcessed::class);
});

it('creates one draft when the same form is submitted twice', function () {
    $requestId = (string) Str::uuid();

    $first = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], $requestId), $this->stock->user);
    $second = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], $requestId), $this->stock->user);

    expect($second->id)->toBe($first->id)
        ->and(DB::table('stock_draft')->count())->toBe(1);
});

it('cancels a draft with a reason', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)]), $this->stock->user);

    expect(fn () => $this->drafts->cancel($draft, $this->stock->user, ' '))->toThrow(PostingRejected::class);

    $this->drafts->cancel($draft, $this->stock->user, 'Order withdrawn');

    expect($draft->fresh()->status)->toBe('CANCELLED')
        ->and(DB::table('stock_draft')->where('id', $draft->id)->value('cancel_reason'))->toBe('Order withdrawn')
        ->and(fn () => $this->drafts->confirm($draft, $this->stock->user))->toThrow(AlreadyProcessed::class);
});

it('keeps a customer user to their customer and their branches', function () {
    $uptown = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'UP', 'name' => 'Uptown']);
    $other = Customer::factory()->create();
    $portal = userWithRole('CUSTOMER', $this->stock->warehouse);
    $portal->forceFill(['customer_id' => $this->stock->customer->id])->save();
    $portal->branches()->attach($this->branch->id);
    $portal = $portal->fresh();

    $mine = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)]), $portal);
    $theirs = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], branch: $uptown), $this->stock->user);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'BEANS', 1)], branch: $uptown), $portal))
        ->toThrow(NotAllowed::class)
        // Customers can't confirm their own drafts by default.
        ->and(fn () => $this->drafts->confirm($mine, $portal))->toThrow(NotAllowed::class)
        // Linking a user to another customer's branch is refused by the database.
        // (in a savepoint, so the refusal doesn't abort the test's transaction)
        ->and(fn () => DB::transaction(fn () => $portal->branches()->attach(CustomerBranch::create(['customer_id' => $other->id, 'code' => 'X', 'name' => 'X'])->id)))
        ->toThrow(QueryException::class);

    $this->actingAs($portal);
    expect(StockDraft::pluck('id')->all())->toBe([$mine->id]);

    $this->actingAs(userWithRole('OPERATOR', $this->depot));
    expect(StockDraft::count())->toBe(0);

    $this->actingAs(userWithRole('OPERATOR', $this->stock->warehouse));
    expect(StockDraft::count())->toBe(2)
        ->and($theirs->exists)->toBeTrue();
});

it('lets staff use any branch and treats a user without a customer as staff', function () {
    $staff = User::factory()->create();

    expect($staff->isCustomerUser())->toBeFalse()
        ->and($staff->canUseBranch($this->branch->id, $this->stock->customer->id))->toBeTrue();
});

/**
 * An order of 4 Piece of Canned Beans from Beirut Main dated today, with the
 * given delivery date and priority.
 */
function deliveryOrder(object $test, ?CarbonImmutable $deliveryDate, ?string $priority, string $type = 'OUT', ?string $version = null): DraftInput
{
    return new DraftInput($type, $test->stock->customer->id, $test->branch->id, $test->stock->warehouse->id, CarbonImmutable::today(), null,
        ['r1' => draftLine($test->stock, 'BEANS', 4)], version: $version, deliveryDate: $deliveryDate, priority: $priority);
}

it('saves and edits an order\'s delivery date and priority, and records the change', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);

    $draft = $this->drafts->save(deliveryOrder($this, CarbonImmutable::today()->addDays(3), 'URGENT'), $this->stock->user);

    expect(DB::table('stock_draft')->where('id', $draft->id)->first(['delivery_date', 'priority']))
        ->toEqual((object) ['delivery_date' => CarbonImmutable::today()->addDays(3)->toDateString(), 'priority' => 'URGENT']);

    $this->drafts->save(deliveryOrder($this, CarbonImmutable::today()->addDays(5), 'LOW', version: version($draft)), $this->stock->user, $draft);

    $update = DB::table('audit_log')->where('entity', 'stock_draft')->where('entity_id', $draft->id)->where('action', 'UPDATE')->latest('id')->first();
    expect(json_decode($update->after_data, true))->toMatchArray(['delivery_date' => CarbonImmutable::today()->addDays(5)->toDateString(), 'priority' => 'LOW'])
        ->and(json_decode($update->before_data, true))->toMatchArray(['priority' => 'URGENT']);
});

it('refuses an order without a delivery date, with one before the order date, or with an unknown priority', function (?int $days, ?string $priority, string $key, string $field) {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);

    try {
        $this->drafts->save(deliveryOrder($this, $days === null ? null : CarbonImmutable::today()->addDays($days), $priority), $this->stock->user);
        $this->fail('The order was saved.');
    } catch (PostingRejected $e) {
        expect($e->getMessage())->toBe(__($key))->and($e->field)->toBe($field);
    }

    expect(DB::table('stock_draft')->count())->toBe(0);
})->with([
    'no delivery date' => [null, 'NORMAL', 'drafts.errors.delivery_date_required', 'delivery_date'],
    'before the order date' => [-1, 'NORMAL', 'drafts.errors.delivery_date_before', 'delivery_date'],
    'unknown priority' => [0, 'SOON', 'drafts.errors.priority_required', 'priority'],
    'no priority' => [0, null, 'drafts.errors.priority_required', 'priority'],
]);

it('keeps no delivery date or priority on a draft receipt', function () {
    $draft = $this->drafts->save(deliveryOrder($this, CarbonImmutable::today(), 'URGENT', type: 'IN'), $this->stock->user);

    expect(DB::table('stock_draft')->where('id', $draft->id)->first(['delivery_date', 'priority']))
        ->toEqual((object) ['delivery_date' => null, 'priority' => null]);
});

it('has the database refuse an order without a priority or delivered before its date', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $draft = $this->drafts->save(deliveryOrder($this, CarbonImmutable::today(), 'NORMAL'), $this->stock->user);

    expect(fn () => DB::transaction(fn () => DB::table('stock_draft')->where('id', $draft->id)->update(['priority' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('stock_draft')->where('id', $draft->id)->update(['delivery_date' => CarbonImmutable::today()->subDay()->toDateString()])))->toThrow(QueryException::class);
});

it('needs a batch on a draft receipt line of an expiry item, with the date the batch already has', function () {
    $soon = now()->addDays(10)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon, batch: 'B-1042')]);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'YOGURT', 6, expiry: $soon, batch: '')]), $this->stock->user))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.batch_required'));

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'YOGURT', 6, expiry: now()->addDays(20)->toDateString(), batch: 'b-1042')]), $this->stock->user))
        ->toThrow(BatchDateMismatch::class);

    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, 'YOGURT', 6, expiry: $soon, batch: 'b-1042')]), $this->stock->user);
    expect(DB::table('stock_draft_line')->where('draft_id', $draft->id)->value('batch_no'))->toBe('B-1042');
});

it('keeps the batch picked on an order and delivers from it', function () {
    $soon = now()->addDays(10)->toDateString();
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $soon, batch: 'A1'),
        $this->stock->line('YOGURT', 6, expiry: $soon, batch: 'B2'),
    ]);

    // Two batches on that date: the order must say which.
    expect(fn () => $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'YOGURT', 2, expiry: $soon)]), $this->stock->user))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.batch_choose'));

    $draft = $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, 'YOGURT', 2, expiry: $soon, batch: 'B2')]), $this->stock->user);
    [$note] = $this->drafts->confirm($draft, $this->stock->user);
    app(PostingService::class)->dispatchDelivery($note, null, null, null, $this->stock->user);
    app(PostingService::class)->deliver($note, Stock::deliveredInFull($note), $this->stock->user);

    expect($this->stock->balance('YOGURT', $soon, batch: 'A1'))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $soon, batch: 'B2'))->toBe(4.0);
});
