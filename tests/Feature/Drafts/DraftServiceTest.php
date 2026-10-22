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
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
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

function draftLine(Stock $stock, Warehouse $warehouse, string $code, int|string $qty, string $unit = 'PC', ?string $expiry = null, array $serials = []): DraftLineInput
{
    return new DraftLineInput($warehouse->id, $stock->id($code), $unit, $qty, $expiry, $serials);
}

/**
 * @param  list<DraftLineInput>  $lines
 */
function draftOf(object $test, string $type, array $lines, ?string $requestId = null, ?string $version = null, ?CustomerBranch $branch = null): DraftInput
{
    $keyed = [];
    foreach ($lines as $i => $line) {
        $keyed['r'.($i + 1)] = $line;
    }

    return new DraftInput($type, $test->stock->customer->id, ($branch ?? $test->branch)->id, CarbonImmutable::today(), 'PO 77', $keyed, $requestId, $version);
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
        draftLine($this->stock, $this->stock->warehouse, 'BEANS', 2, 'BOX'),
        draftLine($this->stock, $this->depot, 'BEANS', 5),
    ]), $this->stock->user);

    $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->depot, 'BEANS', 7)], version: version($draft)), $this->stock->user, $draft);

    $lines = DB::table('stock_draft_line')->where('draft_id', $draft->id)->get();

    expect($draft->doc_no)->toMatch('/^DRR-\d{4}-\d{6}$/')
        ->and($draft->status)->toBe('DRAFT')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->warehouse_id)->toBe($this->depot->id)
        ->and((float) $lines[0]->qty_base)->toBe(7.0)
        ->and(stockCounts())->toBe($before)
        ->and(DB::table('audit_log')->where('entity', 'stock_draft')->where('entity_id', $draft->id)->pluck('action')->all())->toBe(['CREATE', 'UPDATE']);
});

it('confirms 5 lines in 2 warehouses as 2 receipts linked to the draft', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [
        draftLine($this->stock, $this->stock->warehouse, 'BEANS', 2, 'BOX'),
        draftLine($this->stock, $this->depot, 'BEANS', 10),
        draftLine($this->stock, $this->stock->warehouse, 'YOGURT', 6, expiry: now()->addMonth()->toDateString()),
        draftLine($this->stock, $this->depot, 'YOGURT', 1, 'BOX', now()->addMonths(2)->toDateString()),
        draftLine($this->stock, $this->depot, 'PHONE', 2, serials: ['SN-1', 'SN-2']),
    ]), $this->stock->user);

    // Its creator may confirm it.
    $documents = $this->drafts->confirm($draft, $this->stock->user);

    expect($documents)->toHaveCount(2)
        ->and(collect($documents)->pluck('warehouse_id')->all())->toBe([$this->stock->warehouse->id, $this->depot->id])
        ->and(collect($documents)->pluck('status')->unique()->all())->toBe(['POSTED'])
        ->and(collect($documents)->pluck('transaction_type')->unique()->all())->toBe(['IN'])
        ->and(DB::table('stock_transaction')->where('draft_id', $draft->id)->where('customer_branch_id', $this->branch->id)->count())->toBe(2)
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $documents[0]->id)->count())->toBe(2)
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $documents[1]->id)->count())->toBe(3)
        ->and($this->stock->balance('BEANS'))->toBe(24.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->depot))->toBe(10.0)
        ->and($this->stock->balance('YOGURT', warehouse: $this->depot))->toBe(6.0)
        ->and(DB::table('serial_number')->where('warehouse_id', $this->depot->id)->where('status', 'IN_STOCK')->count())->toBe(2)
        ->and($draft->fresh()->status)->toBe('POSTED')
        ->and($draft->fresh()->confirmed_by)->toBe($this->stock->user->id);
});

it('confirms a draft issue from the expiry dates picked on it', function () {
    $soon = now()->addDays(10)->toDateString();
    $later = now()->addDays(40)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon), $this->stock->line('YOGURT', 6, expiry: $later)]);

    $draft = $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, $this->stock->warehouse, 'YOGURT', 4, expiry: $later)]), $this->stock->user);
    $this->drafts->confirm($draft, $this->stock->user);

    expect($this->stock->balance('YOGURT', $soon))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $later))->toBe(2.0);
});

it('creates nothing when one warehouse of a draft issue is short, and says which line', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 20)]);
    $this->stock->post('IN', [$this->stock->line('BEANS', 20)], warehouse: $this->depot);

    $draft = $this->drafts->save(draftOf($this, 'OUT', [
        draftLine($this->stock, $this->stock->warehouse, 'BEANS', 5),
        draftLine($this->stock, $this->depot, 'BEANS', 15),
    ]), $this->stock->user);

    // Stock taken meanwhile: drafts don't reserve it.
    $this->stock->post('OUT', [$this->stock->line('BEANS', 10)], warehouse: $this->depot);
    $before = stockCounts();
    $short = DB::table('stock_draft_line')->where('draft_id', $draft->id)->where('line_no', 2)->value('id');

    expect(fn () => $this->drafts->confirm($draft, $this->stock->user))
        ->toThrow(fn (InsufficientStock $e) => expect($e->row)->toBe($short));

    expect(stockCounts())->toBe($before)
        ->and($draft->fresh()->status)->toBe('DRAFT')
        ->and($this->stock->balance('BEANS'))->toBe(20.0);
});

it('refuses a draft issue for more than is in stock when saving', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);

    expect(fn () => $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 4)]), $this->stock->user))
        ->toThrow(fn (InsufficientStock $e) => expect($e->row)->toBe('r1'));

    expect(DB::table('stock_draft')->count())->toBe(0);
});

it('lets the confirmer change quantities and confirm in one step, or keeps nothing when that fails', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $draft = $this->drafts->save(draftOf($this, 'OUT', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 4)]), $this->stock->user);

    // Too much: nothing is kept, not even the change.
    expect(fn () => $this->drafts->confirm($draft, $this->stock->user, draftOf($this, 'OUT', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 11)], version: version($draft))))
        ->toThrow(InsufficientStock::class);
    expect((float) DB::table('stock_draft_line')->where('draft_id', $draft->id)->value('qty_base'))->toBe(4.0);

    [$issue] = $this->drafts->confirm($draft, $this->stock->user, draftOf($this, 'OUT', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 3)], version: version($draft)));

    $update = DB::table('audit_log')->where('entity', 'stock_draft')->where('action', 'UPDATE')->first();

    expect((float) DB::table('stock_transaction_line')->where('transaction_id', $issue->id)->value('qty_base'))->toBe(3.0)
        ->and($this->stock->balance('BEANS'))->toBe(7.0)
        ->and(json_decode($update->before_data, true)['lines'][0]['qty'])->toBe(4)
        ->and(json_decode($update->after_data, true)['lines'][0]['qty'])->toBe(3);
});

it('needs the confirm permission and every warehouse of the draft to confirm it', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [
        draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1),
        draftLine($this->stock, $this->depot, 'BEANS', 1),
    ]), $this->stock->user);

    $operator = userWithRole('OPERATOR', $this->stock->warehouse, $this->depot);
    $managerHere = userWithRole('MANAGER', $this->stock->warehouse);

    expect(fn () => $this->drafts->confirm($draft, $operator))->toThrow(NotAllowed::class)
        ->and(fn () => $this->drafts->confirm($draft, $managerHere))->toThrow(NotAllowed::class, 'Depot X');

    expect($draft->fresh()->status)->toBe('DRAFT')
        ->and($this->drafts->confirm($draft, userWithRole('MANAGER', $this->stock->warehouse, $this->depot)))->toHaveCount(2);
});

it('confirms a draft once, and no longer edits or cancels it afterwards', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)]), $this->stock->user);
    $version = version($draft);
    $this->drafts->confirm($draft, $this->stock->user);

    expect(fn () => $this->drafts->confirm($draft, $this->stock->user))->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 2)], version: $version), $this->stock->user, $draft))->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->cancel($draft, $this->stock->user, 'wrong'))->toThrow(AlreadyProcessed::class)
        ->and(DB::table('stock_transaction')->where('draft_id', $draft->id)->count())->toBe(1);
});

it('does not overwrite or confirm a draft someone changed since it was opened', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)]), $this->stock->user);
    $opened = version($draft);

    $this->travel(1)->seconds();
    $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 2)], version: $opened), $this->stock->user, $draft);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 3)], version: $opened), $this->stock->user, $draft))
        ->toThrow(AlreadyProcessed::class)
        ->and(fn () => $this->drafts->confirm($draft, $this->stock->user, version: $opened))->toThrow(AlreadyProcessed::class);
});

it('creates one draft when the same form is submitted twice', function () {
    $requestId = (string) Str::uuid();

    $first = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)], $requestId), $this->stock->user);
    $second = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)], $requestId), $this->stock->user);

    expect($second->id)->toBe($first->id)
        ->and(DB::table('stock_draft')->count())->toBe(1);
});

it('cancels a draft with a reason', function () {
    $draft = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)]), $this->stock->user);

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

    $mine = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)]), $portal);
    $theirs = $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)], branch: $uptown), $this->stock->user);

    expect(fn () => $this->drafts->save(draftOf($this, 'IN', [draftLine($this->stock, $this->stock->warehouse, 'BEANS', 1)], branch: $uptown), $portal))
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
