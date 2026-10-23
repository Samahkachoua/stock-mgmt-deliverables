<?php

use App\Livewire\Transactions\TransactionForm;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\PostingService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * CLAUDE.md rule 12: no two lines with the same key in one document.
 *   Receive, Opening Balance, Delivery note, Transfer, Damage: item + unit (+ expiry date)
 *   Adjustment (a count): item (+ expiry date), in any unit
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->stock->user->warehouses()->attach($this->tripoli->id);
    // 60 Canned Beans to work with.
    $this->stock->post('IN', [$this->stock->line('BEANS', 60)]);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

/** Header fields each type needs before it can be saved. */
function headerFor(string $type): array
{
    return match ($type) {
        'DAMAGE' => ['reason' => 'HANDLING'],
        'ADJUSTMENT' => ['reason' => 'STOCK_COUNT_VARIANCE'],
        default => [],
    };
}

function newForm(Stock $stock, string $type, ?Warehouse $to = null): Testable
{
    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => $type])->call('chooseCustomer', $stock->customer->id);

    foreach (headerFor($type) as $field => $value) {
        $form->set($field, $value);
    }

    if ($type === 'TRANSFER') {
        $form->set('toWarehouse', $to->id);
    }

    return $form;
}

/** Posts a document of $type with these lines, through PostingService. */
function postLines(Stock $stock, string $type, array $lines, ?Warehouse $to = null): mixed
{
    $keyed = [];
    foreach ($lines as $i => $line) {
        $keyed['r'.($i + 1)] = $line;
    }

    return app(PostingService::class)->post(
        new Document($type, $stock->warehouse->id, $stock->customer->id, CarbonImmutable::today(), null, $keyed, $to?->id, reason: headerFor($type)['reason'] ?? null),
        $stock->user,
    );
}

function lineCount(): int
{
    return DB::table('stock_transaction_line')->count();
}

// In the form

it('clears a row that repeats another row’s item and unit, says where it is, and focuses that row', function (string $type) {
    $form = newForm($this->stock, $type, $this->tripoli);
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.qty", '5');

    // A new row of the item starts on a unit no row has yet (Piece, then Box, then Pallet);
    // a count takes the item once in any unit, so there the second row already repeats.
    if ($type !== 'ADJUSTMENT') {
        foreach (['BOX', 'PLT'] as $unit) {
            $next = array_key_last($form->get('lines'));
            $form->set("lines.$next.item", $this->stock->id('BEANS'))->assertSet("lines.$next.unit", $unit)->set("lines.$next.qty", '1');
        }
    }

    $second = array_key_last($form->get('lines'));
    $form->set("lines.$second.item", $this->stock->id('BEANS'));

    $message = $type === 'ADJUSTMENT'
        ? __('transactions.form.duplicate.count', ['item' => 'Canned Beans', 'row' => '1'])
        : __('transactions.form.duplicate.unit', ['item' => 'Canned Beans', 'unit' => Format::unit('PC'), 'row' => '1']);

    $form->assertSet("lines.$second.item", null)
        ->assertSet("lines.$second.unit", '')
        ->assertSet("lines.$first.qty", '5')
        ->assertDispatched('toast', toast: ['type' => 'warning', 'message' => $message, 'url' => null, 'link' => null])
        ->assertDispatched('focus-field', id: 'line-'.$first.'-qty');
})->with(['IN', 'OPENING_BALANCE', 'OUT', 'TRANSFER', 'DAMAGE', 'ADJUSTMENT']);

it('clears a row whose unit is changed to the unit of another row of the item', function (string $type) {
    $form = newForm($this->stock, $type, $this->tripoli);
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.qty", '5');
    $second = array_keys($form->get('lines'))[1];

    // Another unit is fine...
    $form->set("lines.$second.item", $this->stock->id('BEANS'))->set("lines.$second.unit", 'BOX');
    expect($form->get("lines.$second.item"))->toBe($this->stock->id('BEANS'));

    // ...until it is changed to the first row's unit.
    $form->set("lines.$second.unit", 'PC')->assertSet("lines.$second.item", null);
})->with(['IN', 'OPENING_BALANCE', 'OUT', 'TRANSFER', 'DAMAGE']);

it('allows the same item in another unit on another row', function (string $type) {
    $form = newForm($this->stock, $type, $this->tripoli);
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.qty", '10');
    $second = array_keys($form->get('lines'))[1];
    $form->set("lines.$second.item", $this->stock->id('BEANS'))->set("lines.$second.unit", 'BOX')->set("lines.$second.qty", '1');

    $before = DB::table('stock_transaction')->where('transaction_type', $type)->count();
    $form->assertSet("lines.$second.item", $this->stock->id('BEANS'))
        ->assertNotDispatched('focus-field')
        ->call('save');

    if ($form->get('confirm') !== null) {
        $form->call('runConfirmed'); // Damage / Opening Balance ask first
    }

    $form->assertHasNoErrors()->assertRedirect();
    expect(DB::table('stock_transaction')->where('transaction_type', $type)->count())->toBe($before + 1);
})->with(['IN', 'OPENING_BALANCE', 'OUT', 'TRANSFER', 'DAMAGE']);

it('shows combined availability over rows of the same item in different units', function (string $type) {
    $form = newForm($this->stock, $type, $this->tripoli);
    [$first] = array_keys($form->get('lines'));
    // 5 Box = 60 Piece: all there is.
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.unit", 'BOX')->set("lines.$first.qty", '5');
    $second = array_keys($form->get('lines'))[1];
    $form->set("lines.$second.item", $this->stock->id('BEANS'))->set("lines.$second.qty", '10');

    expect($form->html())->toContain(e(__('transactions.form.errors.not_enough', ['qty' => '0 Piece'])));
})->with(['OUT', 'TRANSFER', 'DAMAGE']);

it('keeps the expiry date apart: the same item and unit with another expiry date is another line', function () {
    $soon = CarbonImmutable::today()->addDays(30)->toDateString();
    $late = CarbonImmutable::today()->addDays(90)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon), $this->stock->line('YOGURT', 6, expiry: $late)]);

    // Going out: a second row starts on what no row has yet — nearest date first (FEFO), in another unit.
    $form = newForm($this->stock, 'OUT');
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('YOGURT'))->set("lines.$first.qty", '2');
    $second = array_keys($form->get('lines'))[1];
    $form->set("lines.$second.item", $this->stock->id('YOGURT'));

    $form->assertSet("lines.$first.expiry", $soon)->assertSet("lines.$first.unit", 'PC')
        ->assertSet("lines.$second.expiry", $soon)->assertSet("lines.$second.unit", 'BOX');

    // The same unit with the other date is another line.
    $form->set("lines.$second.expiry", $late)->set("lines.$second.unit", 'PC')
        ->assertSet("lines.$second.item", $this->stock->id('YOGURT'));

    // Picking the first row's date as well makes it a repeat.
    $form->set("lines.$second.expiry", $soon)
        ->assertSet("lines.$second.item", null)
        ->assertDispatched('toast', toast: ['type' => 'warning', 'message' => __('transactions.form.duplicate.unit_expiry', [
            'item' => 'Yogurt', 'unit' => Format::unit('PC'), 'expiry' => Format::date(CarbonImmutable::parse($soon)), 'row' => '1',
        ]), 'url' => null, 'link' => null]);
});

it('refuses a typed expiry date that another receipt row of the item and unit already has', function (string $type) {
    $date = CarbonImmutable::today()->addDays(60)->toDateString();
    $form = newForm($this->stock, $type);
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('YOGURT'))->set("lines.$first.expiry", $date)->set("lines.$first.qty", '6');
    $second = array_keys($form->get('lines'))[1];

    // Another date: allowed.
    $form->set("lines.$second.item", $this->stock->id('YOGURT'))->set("lines.$second.expiry", CarbonImmutable::today()->addDays(70)->toDateString());
    expect($form->get("lines.$second.item"))->toBe($this->stock->id('YOGURT'));

    // The same date: cleared.
    $form->set("lines.$second.expiry", $date)->assertSet("lines.$second.item", null);
})->with(['IN', 'OPENING_BALANCE']);

// On save (server side)

it('refuses a document with the same item and unit twice, and saves nothing', function (string $type) {
    $transactions = DB::table('stock_transaction')->count();
    $lines = lineCount();

    expect(fn () => postLines($this->stock, $type, [$this->stock->line('BEANS', 2), $this->stock->line('BEANS', 3)], $this->tripoli))
        ->toThrow(DuplicateLine::class)
        ->and(DB::table('stock_transaction')->count())->toBe($transactions)
        ->and(lineCount())->toBe($lines)
        ->and($this->stock->balance('BEANS'))->toBe(60.0);
})->with(['IN', 'OPENING_BALANCE', 'OUT', 'TRANSFER', 'DAMAGE', 'ADJUSTMENT']);

it('shows both rows in red and refuses to save when repeated rows reach the form anyway', function () {
    $form = newForm($this->stock, 'OUT');
    [$first] = array_keys($form->get('lines'));
    $form->set("lines.$first.item", $this->stock->id('BEANS'))->set("lines.$first.qty", '2');
    $second = array_keys($form->get('lines'))[1];

    // E.g. a stale browser sending rows the form would have cleared.
    $lines = $form->get('lines');
    $lines[$second] = ['item' => $this->stock->id('BEANS'), 'unit' => 'PC', 'qty' => '3', 'expiry' => '', 'serials' => '', 'picked' => []];
    $form->set('lines', $lines);

    expect($form->html())->toContain(e(__('transactions.form.errors.duplicate_row', ['row' => '2'])))
        ->toContain(e(__('transactions.form.errors.duplicate_row', ['row' => '1'])));

    $form->call('save');
    expect(DB::table('stock_transaction')->where('transaction_type', 'OUT')->exists())->toBeFalse();
});

it('allows the same item in another unit on save', function (string $type) {
    $document = postLines($this->stock, $type, [$this->stock->line('BEANS', 2), $this->stock->line('BEANS', 1, 'BOX')], $this->tripoli);

    expect(DB::table('stock_transaction_line')->where('transaction_id', $document->id)->count())->toBe(2);
})->with(['IN', 'OPENING_BALANCE', 'OUT', 'TRANSFER', 'DAMAGE']);

it('counts an item once, whatever the unit', function () {
    expect(fn () => postLines($this->stock, 'ADJUSTMENT', [$this->stock->line('BEANS', 50), $this->stock->line('BEANS', 4, 'BOX')]))
        ->toThrow(DuplicateLine::class, __('transactions.form.duplicate.count', ['item' => 'Canned Beans', 'row' => '1']))
        ->and($this->stock->balance('BEANS'))->toBe(60.0);
});

it('checks availability on the combined quantity of the item’s lines: 5 Box + 10 Piece is more than 60 Piece', function (string $type) {
    $lines = lineCount();

    expect(fn () => postLines($this->stock, $type, [$this->stock->line('BEANS', 5, 'BOX'), $this->stock->line('BEANS', 10)], $this->tripoli))
        ->toThrow(InsufficientStock::class)
        ->and(lineCount())->toBe($lines)
        ->and($this->stock->balance('BEANS'))->toBe(60.0);

    // Exactly what is there is fine.
    postLines($this->stock, $type, [$this->stock->line('BEANS', 4, 'BOX'), $this->stock->line('BEANS', 12)], $this->tripoli);
    expect($this->stock->balance('BEANS'))->toBe(0.0);
})->with(['OUT', 'TRANSFER', 'DAMAGE']);

it('takes a receipt of the same item and unit with another expiry date, and refuses the same date', function (string $type) {
    $soon = CarbonImmutable::today()->addDays(30)->toDateString();
    $late = CarbonImmutable::today()->addDays(90)->toDateString();

    postLines($this->stock, $type, [$this->stock->line('YOGURT', 6, expiry: $soon), $this->stock->line('YOGURT', 4, expiry: $late)]);
    expect($this->stock->balance('YOGURT', $soon))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $late))->toBe(4.0);

    expect(fn () => postLines($this->stock, $type, [$this->stock->line('YOGURT', 1, expiry: $soon), $this->stock->line('YOGURT', 2, expiry: $soon)]))
        ->toThrow(DuplicateLine::class, __('transactions.form.duplicate.unit_expiry', [
            'item' => 'Yogurt', 'unit' => Format::unit('PC'), 'expiry' => Format::date(CarbonImmutable::parse($soon)), 'row' => '1',
        ]));
})->with(['IN', 'OPENING_BALANCE']);

it('counts each expiry date of an item once', function () {
    $soon = CarbonImmutable::today()->addDays(30)->toDateString();
    $late = CarbonImmutable::today()->addDays(90)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon), $this->stock->line('YOGURT', 6, expiry: $late)]);

    postLines($this->stock, 'ADJUSTMENT', [$this->stock->line('YOGURT', 5, expiry: $soon), $this->stock->line('YOGURT', 7, expiry: $late)]);
    expect($this->stock->balance('YOGURT'))->toBe(12.0);

    expect(fn () => postLines($this->stock, 'ADJUSTMENT', [$this->stock->line('YOGURT', 4, expiry: $soon), $this->stock->line('YOGURT', 1, 'BOX', expiry: $soon)]))
        ->toThrow(DuplicateLine::class);
});

// Database safety net

it('refuses a repeated line written straight into the database', function (bool $expiry) {
    $date = CarbonImmutable::today()->addDays(30)->toDateString();
    $document = $expiry
        ? postLines($this->stock, 'IN', [$this->stock->line('YOGURT', 6, expiry: $date)])
        : postLines($this->stock, 'IN', [$this->stock->line('BEANS', 6)]);
    $line = (array) DB::table('stock_transaction_line')->where('transaction_id', $document->id)->first();
    unset($line['id'], $line['created_at']);

    // In a savepoint, so the test's own transaction stays usable after the refusal.
    expect(fn () => DB::transaction(fn () => DB::table('stock_transaction_line')->insert(['line_no' => 2] + $line)))
        ->toThrow(UniqueConstraintViolationException::class);

    // Another expiry date (or none against one) is another line.
    DB::transaction(fn () => DB::table('stock_transaction_line')->insert(['line_no' => 3, 'expiry_date' => $expiry ? null : $date] + $line));
    expect(DB::table('stock_transaction_line')->where('transaction_id', $document->id)->count())->toBe(2);

    // The extra line has no movement: remove it so the ledger check after the test holds.
    DB::table('stock_transaction_line')->where('transaction_id', $document->id)->where('line_no', 3)->delete();
})->with(['no expiry' => false, 'with expiry' => true]);
