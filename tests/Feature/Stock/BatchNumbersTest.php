<?php

use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\Exceptions\BatchDateMismatch;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\PostingService;
use App\Services\Stock\ReceivedLine;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stock;

/*
 * Batch numbers (CLAUDE.md rule 9): stock of an expiry item is kept per lot,
 * expiry date + batch. A batch has one expiry date per item; receiving needs
 * one; going out takes the batch picked.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->soon = CarbonImmutable::today()->addDays(10)->toDateString();
    $this->late = CarbonImmutable::today()->addDays(60)->toDateString();
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([])
        ->and(Stock::transitMismatches())->toBe([]);
});

it('keeps the batch on lines, movements, balances and in transit', function () {
    $tables = ['stock_transaction_line', 'stock_draft_line', 'stock_movement', 'stock_balance', 'in_transit_balance'];

    foreach ($tables as $table) {
        expect(DB::selectOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'batch_no'",
            [$table],
        )?->data_type)->toBe('character varying');
    }

    expect(DB::selectOne("SELECT relrowsecurity FROM pg_class WHERE relname = 'product_batch'")->relrowsecurity)->toBeIn([true, '1', 't']);
});

it('needs a batch number to receive an expiry item, and keeps it upper case', function () {
    expect(fn () => $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->soon, batch: '')]))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.batch_required'));

    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->soon, batch: ' b-1042 ')]);

    expect(DB::table('stock_balance')->where('product_id', $this->stock->id('YOGURT'))->value('batch_no'))->toBe('B-1042')
        ->and(DB::table('product_batch')->where('batch_no', 'B-1042')->value('expiry_date'))->toBe($this->soon);
});

it('gives a batch one expiry date, in the app and in the database', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'B-1042')]);

    expect(fn () => $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->late, batch: 'B-1042')]))
        ->toThrow(BatchDateMismatch::class, __('stock.errors.batch_other_date', [
            'batch' => 'B-1042', 'item' => 'Yogurt', 'date' => Format::date(CarbonImmutable::parse($this->soon)),
        ]));

    // Same batch, same date: more of it.
    $this->stock->post('IN', [$this->stock->line('YOGURT', 4, expiry: $this->soon, batch: 'B-1042')]);
    expect($this->stock->balance('YOGURT', $this->soon, batch: 'B-1042'))->toBe(10.0);

    $line = (array) DB::table('stock_transaction_line')->where('product_id', $this->stock->id('YOGURT'))->first();
    unset($line['id'], $line['created_at']);

    expect(fn () => DB::transaction(fn () => DB::table('stock_transaction_line')->insert(['line_no' => 9, 'expiry_date' => $this->late] + $line)))
        ->toThrow(QueryException::class, 'fk_transaction_line_batch');
});

it('gives only expiry items batches', function () {
    expect(fn () => DB::transaction(fn () => DB::table('product_batch')->insert(['product_id' => $this->stock->id('BEANS'), 'batch_no' => 'B1', 'expiry_date' => $this->soon])))
        ->toThrow(QueryException::class, 'Only expiry-tracked products have batches');
});

it('keeps two batches of one expiry date apart and takes the one picked', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 4, expiry: $this->soon, batch: 'B2'),
    ]);

    // Two batches on that date: the user must say which.
    expect(fn () => $this->stock->post('OUT', [$this->stock->line('YOGURT', 2, expiry: $this->soon)]))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.batch_choose'));

    $this->stock->post('OUT', [$this->stock->line('YOGURT', 3, expiry: $this->soon, batch: 'B2')]);

    expect($this->stock->balance('YOGURT', $this->soon, batch: 'A1'))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $this->soon, batch: 'B2'))->toBe(1.0);

    // Never more than the batch picked has.
    expect(fn () => $this->stock->post('OUT', [$this->stock->line('YOGURT', 2, expiry: $this->soon, batch: 'B2')]))
        ->toThrow(InsufficientStock::class);
});

it('repeats no lot on one document, but takes two batches of a date as two lines', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 4, expiry: $this->soon, batch: 'B2'),
    ]);

    $this->stock->post('OUT', [
        $this->stock->line('YOGURT', 1, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 1, expiry: $this->soon, batch: 'B2'),
    ]);

    expect(fn () => $this->stock->post('OUT', [
        $this->stock->line('YOGURT', 1, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 1, expiry: $this->soon, batch: 'a1'),
    ]))->toThrow(DuplicateLine::class, __('transactions.form.duplicate.unit_expiry_batch', [
        'item' => 'Yogurt', 'unit' => Format::unit('PC'), 'batch' => 'A1', 'expiry' => Format::date(CarbonImmutable::parse($this->soon)), 'row' => '1',
    ]));
});

it('takes the nearest expiry first, then batch by batch, when no date is given', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->late, batch: 'Z9'),
        $this->stock->line('YOGURT', 3, expiry: $this->soon, batch: 'B2'),
        $this->stock->line('YOGURT', 2, expiry: $this->soon, batch: 'A1'),
    ]);

    // Damage without a date goes FEFO.
    $damage = $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 7)], $this->stock->user, reason: 'HANDLING');

    $taken = DB::table('stock_movement')->where('transaction_id', $damage->id)->orderBy('seq')
        ->get(['expiry_date', 'batch_no', 'qty_base'])->map(fn ($m) => [$m->batch_no, (float) $m->qty_base])->all();

    expect($taken)->toBe([['A1', 2.0], ['B2', 3.0], ['Z9', 2.0]]);
});

it('blocks negative stock per batch in the database', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 2, expiry: $this->soon, batch: 'A1')]);
    $receipt = DB::table('stock_transaction')->latest('id')->first();
    $line = DB::table('stock_transaction_line')->where('transaction_id', $receipt->id)->value('id');

    expect(fn () => DB::transaction(fn () => DB::select('SELECT fn_post_movement(?, ?, ?, ?, ?, ?::date, ?, ?::movement_direction, ?)', [
        $receipt->id, $line, $this->stock->warehouse->id, $this->stock->customer->id, $this->stock->id('YOGURT'), $this->soon, 'A1', 'OUT', 3,
    ])))->toThrow(QueryException::class, 'Negative stock blocked');
});

it('sends a transfer with its batches and receives them, short receipts included', function () {
    $tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $receiver = userWithRole('OPERATOR', $tripoli);
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'B2'),
    ]);

    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 5, expiry: $this->soon, batch: 'B2')], $tripoli);

    expect(DB::table('in_transit_balance')->where('transaction_id', $transfer->id)->pluck('qty_base', 'batch_no')->map(fn ($q) => (float) $q)->all())
        ->toBe(['B2' => 5.0]);

    $line = DB::table('stock_transaction_line')->where('transaction_id', $transfer->id)->value('id');
    app(PostingService::class)->receive($transfer, [$line => new ReceivedLine(4, 'MISSING')], $receiver);

    expect($this->stock->balance('YOGURT', $this->soon, $tripoli, 'B2'))->toBe(4.0)
        ->and($this->stock->balance('YOGURT', $this->soon, batch: 'B2'))->toBe(1.0)
        ->and($this->stock->balance('YOGURT', $this->soon, batch: 'A1'))->toBe(6.0);
});

it('counts each batch on its own, and registers a batch found while counting', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'A1'),
        $this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'B2'),
    ]);

    $this->stock->post('ADJUSTMENT', [
        $this->stock->line('YOGURT', 5, expiry: $this->soon, batch: 'A1'),
        // A batch nobody received: found while counting.
        $this->stock->line('YOGURT', 3, expiry: $this->late, batch: 'C3'),
    ], $this->stock->user, reason: 'STOCK_COUNT_VARIANCE');

    expect($this->stock->balance('YOGURT', $this->soon, batch: 'A1'))->toBe(5.0)
        ->and($this->stock->balance('YOGURT', $this->soon, batch: 'B2'))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $this->late, batch: 'C3'))->toBe(3.0)
        ->and(DB::table('product_batch')->where('batch_no', 'C3')->value('expiry_date'))->toBe($this->late);

    // Two batches on that date: a count must say which (straight to the
    // service: the Stock helper would give the line a batch).
    expect(fn () => app(PostingService::class)->post(new Document('ADJUSTMENT', $this->stock->warehouse->id, $this->stock->customer->id, CarbonImmutable::today(), null,
        ['r1' => $this->stock->line('YOGURT', 1, expiry: $this->soon)], reason: 'STOCK_COUNT_VARIANCE'), $this->stock->user))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.batch_choose'));
});

it('limits a batch going out on a document dated back to what it had that day', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->late, batch: 'A1')], date: CarbonImmutable::today()->subDays(5));
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->late, batch: 'B2')], date: CarbonImmutable::today()->subDay());

    // B2 arrived yesterday: nothing of it three days ago, but A1 was there.
    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 1, expiry: $this->late, batch: 'B2')], $this->stock->user, reason: 'HANDLING', date: CarbonImmutable::today()->subDays(3)))
        ->toThrow(InsufficientStock::class);

    $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 2, expiry: $this->late, batch: 'A1')], $this->stock->user, reason: 'HANDLING', date: CarbonImmutable::today()->subDays(3));

    expect($this->stock->balance('YOGURT', $this->late, batch: 'A1'))->toBe(4.0);
});

it('still lets stock from before batches go out as "no batch"', function () {
    // As a database from before batches: stock of an expiry date without a batch.
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $this->soon, batch: 'A1')]);
    DB::table('stock_movement')->update(['batch_no' => null]);
    DB::table('stock_transaction_line')->update(['batch_no' => null]);
    DB::table('stock_balance')->update(['batch_no' => null]);

    $this->stock->post('OUT', [$this->stock->line('YOGURT', 2, expiry: $this->soon, batch: '')]);
    $this->stock->post('OUT', [$this->stock->line('YOGURT', 1, expiry: $this->soon)]);

    expect($this->stock->balance('YOGURT', $this->soon, batch: ''))->toBe(3.0);
});
