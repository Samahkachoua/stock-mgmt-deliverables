<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Stock\Availability;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\Exceptions\SerialProblem;
use App\Services\Stock\Exceptions\StockAlreadyUsed;
use App\Services\Stock\PostingService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
});

afterEach(function () {
    // The ledger invariant holds after every test, whatever it did.
    expect(Stock::ledgerMismatches())->toBe([]);
});

function counts(): array
{
    return [
        'transactions' => DB::table('stock_transaction')->count(),
        'lines' => DB::table('stock_transaction_line')->count(),
        'movements' => DB::table('stock_movement')->count(),
        'balance' => (float) DB::table('stock_balance')->sum('qty_base'),
        'serials' => DB::table('serial_number')->count(),
        'audit' => DB::table('audit_log')->count(),
    ];
}

// Receiving

it('converts to base units on save: 3 Box are 36 Piece', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX')]);

    $line = DB::table('stock_transaction_line')->where('transaction_id', $receipt->id)->first();
    $movement = DB::table('stock_movement')->where('transaction_id', $receipt->id)->first();

    expect($receipt->status)->toBe('POSTED')
        ->and($receipt->transaction_type)->toBe('IN')
        ->and((float) $line->qty_entered)->toBe(3.0)
        ->and((float) $line->qty_base)->toBe(36.0)
        ->and(DB::table('unit')->where('id', $line->unit_id)->value('code'))->toBe('BOX')
        ->and($movement->direction)->toBe('IN')
        ->and((float) $movement->qty_base)->toBe(36.0)
        ->and($this->stock->balance('BEANS'))->toBe(36.0);
});

it('numbers documents per type: PREFIX-YYYY-000123, one sequence each', function () {
    $first = $this->stock->post('IN', [$this->stock->line('BEANS', 1)]);
    $second = $this->stock->post('IN', [$this->stock->line('BEANS', 1)]);
    $opening = $this->stock->post('OPENING_BALANCE', [$this->stock->line('BEANS', 1)]);
    $issue = $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]);
    $year = now('Asia/Beirut')->year;

    expect($first->doc_no)->toMatch("/^REC-$year-\\d{6}$/")
        ->and($opening->doc_no)->toMatch("/^OPB-$year-\\d{6}$/")
        ->and($issue->doc_no)->toMatch("/^ISS-$year-\\d{6}$/")
        ->and((int) substr($second->doc_no, -6))->toBe((int) substr($first->doc_no, -6) + 1);
});

it('records the posting in the audit log, by the user who posted', function () {
    $receipt = $this->stock->post('OPENING_BALANCE', [$this->stock->line('BEANS', 5)]);

    $audit = DB::table('audit_log')->where('entity', 'stock_transaction')->where('entity_id', $receipt->id)->first();

    expect($audit->action)->toBe('POST')
        ->and($audit->user_id)->toBe($this->stock->user->id)
        ->and(json_decode($audit->after_data, true))->toMatchArray(['doc_no' => $receipt->doc_no, 'status' => 'POSTED']);
});

it('keeps the stock of an expiry item per expiry date: the same date adds up', function () {
    $soon = now()->addMonths(2)->toDateString();
    $late = now()->addMonths(5)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 10, expiry: $soon)]);
    $this->stock->post('IN', [$this->stock->line('YOGURT', 5, expiry: $soon), $this->stock->line('YOGURT', 2, expiry: $late)]);

    expect(DB::table('stock_balance')->where('product_id', $this->stock->id('YOGURT'))->count())->toBe(2)
        ->and($this->stock->balance('YOGURT', $soon))->toBe(15.0)
        ->and($this->stock->balance('YOGURT', $late))->toBe(2.0)
        ->and(DB::table('stock_transaction_line')->where('product_id', $this->stock->id('YOGURT'))->pluck('expiry_date')->unique()->sort()->values()->all())->toBe([$soon, $late]);
});

it('requires a valid expiry date for expiry-tracked items', function (?string $expiry) {
    $before = counts();

    try {
        $this->stock->post('IN', [$this->stock->line('YOGURT', 1, expiry: $expiry)]);
        $this->fail('Expected PostingRejected');
    } catch (PostingRejected $e) {
        expect($e->field)->toBe('expiry')
            ->and($e->getMessage())->toBe(__('transactions.form.errors.expiry_required'));
    }

    expect(counts())->toBe($before);
})->with([
    'no expiry' => [null],
    'not a date' => ['2030-02-30'],
]);

// Issuing

it('takes stock out and keeps balances right', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 2, 'BOX')]);
    $issue = $this->stock->post('OUT', [$this->stock->line('BEANS', 10)]);

    expect($this->stock->balance('BEANS'))->toBe(14.0)
        ->and(DB::table('stock_movement')->where('transaction_id', $issue->id)->value('direction'))->toBe('OUT');
});

it('refuses to issue more than is available, and saves nothing', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 64)]);
    $before = counts();

    try {
        // The first line alone would fit; the second makes the whole document fail.
        $this->stock->post('OUT', [$this->stock->line('BEANS', 60), $this->stock->line('BEANS', 1, 'BOX')]);
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe(4.0)
            ->and($e->requested)->toBe(12.0)
            ->and($e->row)->toBe('r2')
            ->and($e->getMessage())->toBe(__('stock.errors.not_enough', ['qty' => '4 Piece', 'item' => 'Canned Beans', 'warehouse' => 'Beirut Main']));
    }

    expect(counts())->toBe($before)
        ->and($this->stock->balance('BEANS'))->toBe(64.0);
});

it('refuses to issue an item with no stock at all', function () {
    expect(fn () => $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]))->toThrow(InsufficientStock::class);
});

it('turns the database’s own negative-stock refusal into the same error', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);

    // Pretend the pre-check saw plenty, so fn_apply_balance is the one that refuses.
    app()->instance(Availability::class, new class extends Availability
    {
        public function lockNoExpiry(string $warehouseId, string $customerId, string $productId): float
        {
            return 1000;
        }
    });

    $before = counts();

    try {
        $this->stock->post('OUT', [$this->stock->line('BEANS', 8)]);
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe(5.0)->and($e->requested)->toBe(8.0);
    }

    expect(counts())->toBe($before);
});

// FEFO

it('issues the nearest expiry first, across expiry dates, and skips expired stock', function () {
    [$late, $soon, $gone] = [now()->addDays(40)->toDateString(), now()->addDays(10)->toDateString(), now()->subDay()->toDateString()];
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 10, expiry: $late),
        $this->stock->line('YOGURT', 10, expiry: $soon),
        $this->stock->line('YOGURT', 10, expiry: $gone),
    ]);

    $issue = $this->stock->post('OUT', [$this->stock->line('YOGURT', 15)]);

    $taken = DB::table('stock_movement')->where('transaction_id', $issue->id)->orderBy('expiry_date')
        ->pluck('qty_base', 'expiry_date')->map(fn ($qty) => (float) $qty)->all();

    expect($taken)->toBe([$soon => 10.0, $late => 5.0])
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $issue->id)->count())->toBe(1)
        ->and($this->stock->balance('YOGURT', $gone))->toBe(10.0);
});

it('can issue stock on its expiry day', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 5, expiry: now('Asia/Beirut')->toDateString())]);

    $this->stock->post('OUT', [$this->stock->line('YOGURT', 5)]);

    expect($this->stock->balance('YOGURT'))->toBe(0.0);
});

it('says how much has expired when there is not enough', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: now()->addDays(10)->toDateString()),
        $this->stock->line('YOGURT', 6, expiry: now()->subDays(3)->toDateString()),
    ]);

    expect(fn () => $this->stock->post('OUT', [$this->stock->line('YOGURT', 5)]))
        ->toThrow(InsufficientStock::class, __('stock.errors.not_enough_with_expired', ['qty' => '4 Piece', 'expired' => '1 Box', 'item' => 'Yogurt', 'warehouse' => 'Beirut Main']));
});

it('says so when only expired stock is left', function () {
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: now()->subDays(3)->toDateString())]);

    try {
        $this->stock->post('OUT', [$this->stock->line('YOGURT', 1)]);
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe(0.0)
            ->and($e->expired)->toBe(6.0)
            ->and($e->getMessage())->toBe(__('stock.errors.only_expired', ['qty' => '1 Box', 'item' => 'Yogurt', 'warehouse' => 'Beirut Main']));
    }
});

// Chosen expiry date

it('issues from the expiry date chosen on the line, not the nearest expiry', function () {
    [$soon, $late] = [now()->addDays(10)->toDateString(), now()->addDays(40)->toDateString()];
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 10, expiry: $soon),
        $this->stock->line('YOGURT', 10, expiry: $late),
    ]);

    $issue = $this->stock->post('OUT', [$this->stock->line('YOGURT', 4, expiry: $late)]);

    expect($this->stock->balance('YOGURT', $soon))->toBe(10.0)
        ->and($this->stock->balance('YOGURT', $late))->toBe(6.0)
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $issue->id)->value('expiry_date'))->toBe($late);
});

it('refuses more than the chosen expiry date holds, even when the item has more, and saves nothing', function () {
    [$soon, $late] = [now()->addDays(10)->toDateString(), now()->addDays(40)->toDateString()];
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 10, expiry: $soon),
        $this->stock->line('YOGURT', 3, expiry: $late),
    ]);
    $before = counts();

    try {
        $this->stock->post('OUT', [$this->stock->line('YOGURT', 4, expiry: $late)]);
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe(3.0)->and($e->row)->toBe('r1');
    }

    expect(counts())->toBe($before)
        ->and($this->stock->balance('YOGURT', $late))->toBe(3.0)
        ->and($this->stock->balance('YOGURT', $soon))->toBe(10.0);
});

it('refuses to issue a chosen expiry date that has expired or is not here', function (int $days, string $message) {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 5, expiry: now()->subDay()->toDateString()),
        $this->stock->line('YOGURT', 5, expiry: now()->addDays(10)->toDateString()),
    ]);
    $expiry = now()->addDays($days)->toDateString();

    try {
        $this->stock->post('OUT', [$this->stock->line('YOGURT', 1, expiry: $expiry)]);
        $this->fail('Expected PostingRejected');
    } catch (PostingRejected $e) {
        expect($e->getMessage())->toBe(__($message, ['date' => Format::date(CarbonImmutable::parse($expiry))]))
            ->and($e->field)->toBe('expiry');
    }

    expect($this->stock->balance('YOGURT'))->toBe(10.0);
})->with([
    'expired' => [-1, 'stock.errors.expiry_expired'],
    'not here' => [20, 'transactions.form.errors.expiry_not_here'],
]);

// Serial numbers

it('registers one serial per piece and issues only serials in stock here', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);

    expect(DB::table('serial_number')->where('status', 'IN_STOCK')->where('warehouse_id', $this->stock->warehouse->id)->count())->toBe(2);

    $issue = $this->stock->post('OUT', [$this->stock->line('PHONE', 1, serials: ['SN-2'])]);

    expect(DB::table('serial_number')->where('serial_no', 'SN-2')->first())
        ->status->toBe('ISSUED')->warehouse_id->toBeNull()
        ->and(DB::table('stock_transaction_line_serial')->join('stock_transaction_line as l', 'l.id', '=', 'line_id')->where('l.transaction_id', $issue->id)->count())->toBe(1)
        ->and($this->stock->balance('PHONE'))->toBe(1.0);
});

it('rejects duplicate serials', function (array $lines, string $expected) {
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);
    $before = counts();

    expect(fn () => $this->stock->post('IN', array_map(fn ($serials) => $this->stock->line('PHONE', count($serials), serials: $serials), $lines)))
        ->toThrow(SerialProblem::class, __($expected, ['serial' => $lines[array_key_last($lines)][0]]));

    expect(counts())->toBe($before);
})->with([
    'twice on one line' => [[['SN-9', 'SN-9']], 'stock.errors.serial_twice'],
    'on two lines' => [[['SN-8'], ['SN-8']], 'stock.errors.serial_twice'],
    'already in stock' => [[['SN-1']], 'stock.errors.serial_in_stock'],
]);

it('needs exactly one serial per piece', function () {
    expect(fn () => $this->stock->post('IN', [$this->stock->line('PHONE', 3, serials: ['SN-1', 'SN-2'])]))
        ->toThrow(PostingRejected::class, __('stock.errors.serial_count', ['count' => 3]));
});

it('refuses to issue a serial that is not in stock in this warehouse', function () {
    $other = Warehouse::factory()->create();
    $this->stock->user->warehouses()->attach($other);
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-ELSEWHERE'])], warehouse: $other);
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-HERE'])]);
    $before = counts();

    foreach (['SN-ELSEWHERE', 'SN-UNKNOWN'] as $serial) {
        expect(fn () => $this->stock->post('OUT', [$this->stock->line('PHONE', 1, serials: [$serial])]))
            ->toThrow(SerialProblem::class, __('stock.errors.serial_not_in_stock', ['serial' => $serial]));
    }

    expect(counts())->toBe($before);
});

it('lets an issued serial be received again', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);
    $this->stock->post('OUT', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);

    expect(DB::table('serial_number')->where('serial_no', 'SN-1')->value('status'))->toBe('IN_STOCK')
        ->and(DB::table('serial_number')->count())->toBe(1);
});

it('uses only the base unit for serial items', function () {
    expect(fn () => $this->stock->post('IN', [$this->stock->line('PHONE', 1, 'BOX', serials: ['SN-1'])]))
        ->toThrow(PostingRejected::class);
});

// Cancelling

it('cancels a receipt by reversing exactly what it posted', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    $receipt = $this->stock->post('IN', [
        $this->stock->line('BEANS', 2, 'BOX'),
        $this->stock->line('YOGURT', 6, expiry: now()->addMonth()->toDateString()),
    ]);

    app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake');

    $movements = DB::table('stock_movement')->where('transaction_id', $receipt->id)->get();

    expect($receipt->fresh()->status)->toBe('CANCELLED')
        ->and($movements->where('direction', 'IN')->sum('qty_base'))->toEqual($movements->where('direction', 'OUT')->sum('qty_base'))
        ->and($movements)->toHaveCount(4)
        ->and($this->stock->balance('BEANS'))->toBe(5.0)
        ->and($this->stock->balance('YOGURT'))->toBe(0.0)
        ->and(DB::table('audit_log')->where('entity_id', $receipt->id)->orderBy('id')->pluck('action')->all())->toBe(['POST', 'CANCEL']);
});

it('cancels an issue by putting the stock back into the same expiry dates and serials', function () {
    [$soon, $late] = [now()->addDays(5)->toDateString(), now()->addDays(50)->toDateString()];
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $soon),
        $this->stock->line('YOGURT', 10, expiry: $late),
        $this->stock->line('PHONE', 1, serials: ['SN-1']),
    ]);
    $issue = $this->stock->post('OUT', [$this->stock->line('YOGURT', 6), $this->stock->line('PHONE', 1, serials: ['SN-1'])]);

    app(PostingService::class)->cancel($issue, $this->stock->user, 'Entered by mistake');

    expect($this->stock->balance('YOGURT', $soon))->toBe(4.0)
        ->and($this->stock->balance('YOGURT', $late))->toBe(10.0)
        ->and(DB::table('serial_number')->where('serial_no', 'SN-1')->value('status'))->toBe('IN_STOCK');
});

it('refuses to cancel a receipt whose stock has already gone out', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $this->stock->post('OUT', [$this->stock->line('BEANS', 4)]);
    $before = counts();

    expect(fn () => app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake'))
        ->toThrow(StockAlreadyUsed::class);

    expect(counts())->toBe($before)
        ->and($receipt->fresh()->status)->toBe('POSTED');
});

it('refuses to cancel a receipt whose serial has gone out', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);
    $this->stock->post('OUT', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);

    expect(fn () => app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake'))
        ->toThrow(StockAlreadyUsed::class);
});

it('cancels only once', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake');

    expect(fn () => app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake'))
        ->toThrow(PostingRejected::class, __('stock.errors.cannot_cancel_status'));
});

// Rules on the whole document

it('only posts in the user’s warehouses', function () {
    $other = Warehouse::factory()->create();

    expect(fn () => $this->stock->post('IN', [$this->stock->line('BEANS', 1)], warehouse: $other))
        ->toThrow(NotAllowed::class, __('stock.errors.warehouse_not_allowed'));
});

it('refuses items of another customer and units the item does not have', function () {
    $other = Customer::factory()->create();
    $foreign = Product::create(['customer_id' => $other->id, 'sku' => 'X', 'name_en' => 'X', 'base_unit_id' => $this->stock->items['BEANS']->base_unit_id]);

    expect(fn () => app(PostingService::class)->post(new Document('IN', $this->stock->warehouse->id, $this->stock->customer->id, now()->toImmutable(), null,
        ['r1' => new DocumentLine($foreign->id, 'PC', 1)]), $this->stock->user))
        ->toThrow(PostingRejected::class)
        ->and(fn () => $this->stock->post('IN', [$this->stock->line('BEANS', 1, 'CTN')]))
        ->toThrow(PostingRejected::class);
});
