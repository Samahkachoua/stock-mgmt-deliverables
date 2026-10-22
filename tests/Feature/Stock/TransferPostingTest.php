<?php

use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Availability;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\PostingService;
use App\Services\Stock\ReceivedLine;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->receiver = userWithRole('OPERATOR', $this->tripoli);
    // Two expiry dates of the yogurt.
    $this->soon = now()->addDays(5)->toDateString();
    $this->late = now()->addDays(50)->toDateString();
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([])
        ->and(Stock::transitMismatches())->toBe([]);
});

/**
 * @return array<string, string> line id => id, in line order
 */
function lineIds(StockTransaction $transfer): array
{
    return DB::table('stock_transaction_line')->where('transaction_id', $transfer->id)->orderBy('line_no')->pluck('id', 'id')->all();
}

function receive(StockTransaction $transfer, array $received, User $by): void
{
    app(PostingService::class)->receive($transfer, $received, $by);
}

// Dispatch

it('dispatches: stock leaves the source and is in transit, available nowhere', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5, 'BOX')]);

    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);

    expect($transfer->status)->toBe('DISPATCHED')
        ->and($transfer->to_warehouse_id)->toBe($this->tripoli->id)
        ->and($transfer->doc_no)->toStartWith('TRF-')
        ->and(DB::table('stock_transaction')->where('id', $transfer->id)->value('dispatched_by'))->toBe($this->stock->user->id)
        ->and($this->stock->balance('BEANS'))->toBe(36.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(24.0)
        ->and(app(Availability::class)->quantity($this->tripoli->id, $this->stock->customer->id, $this->stock->id('BEANS')))->toBe(0.0);
});

it('dispatches expiry items nearest expiry first, in transit per expiry date', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
    ]);

    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 6)], $this->tripoli);

    $inTransit = DB::table('in_transit_balance')
        ->where('transaction_id', $transfer->id)->pluck('qty_base', 'expiry_date')->map(fn ($q) => (float) $q)->all();

    expect($inTransit)->toEqualCanonicalizing([$this->soon => 4.0, $this->late => 2.0]);
});

it('sends the expiry date chosen on the line, and receives it with the same date', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
    ]);

    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 6, expiry: $this->late)], $this->tripoli);

    $inTransit = DB::table('in_transit_balance')
        ->where('transaction_id', $transfer->id)->pluck('qty_base', 'expiry_date')->map(fn ($q) => (float) $q)->all();

    expect($inTransit)->toBe([$this->late => 6.0])
        ->and($this->stock->balance('YOGURT', $this->soon))->toBe(4.0)
        ->and($this->stock->balance('YOGURT', $this->late))->toBe(4.0);

    [$yogurt] = array_values(lineIds($transfer));
    receive($transfer, [$yogurt => new ReceivedLine(6)], $this->receiver);

    expect($this->stock->balance('YOGURT', $this->late, $this->tripoli))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $this->soon, $this->tripoli))->toBe(0.0);
});

it('refuses to send more than the chosen expiry date holds', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
    ]);

    expect(fn () => $this->stock->dispatch([$this->stock->line('YOGURT', 5, expiry: $this->soon)], $this->tripoli))->toThrow(InsufficientStock::class);

    expect(DB::table('stock_transaction')->where('transaction_type', 'TRANSFER')->count())->toBe(0)
        ->and($this->stock->inTransit('YOGURT'))->toBe(0.0);
});

it('refuses to send more than is available, and saves nothing', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);

    expect(fn () => $this->stock->dispatch([$this->stock->line('BEANS', 11)], $this->tripoli))->toThrow(InsufficientStock::class);

    expect(DB::table('stock_transaction')->where('transaction_type', 'TRANSFER')->count())->toBe(0)
        ->and($this->stock->inTransit('BEANS'))->toBe(0.0);
});

it('needs another active warehouse as destination', function (string $case) {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $to = match ($case) {
        'same' => $this->stock->warehouse,
        'inactive' => Warehouse::factory()->create(['active' => false]),
    };

    expect(fn () => $this->stock->dispatch([$this->stock->line('BEANS', 1)], $to))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.to_warehouse_invalid'));
})->with(['same', 'inactive']);

it('puts serials in transit while they travel', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);
    $this->stock->dispatch([$this->stock->line('PHONE', 1, serials: ['SN-1'])], $this->tripoli);

    expect(DB::table('serial_number')->where('serial_no', 'SN-1')->first())->status->toBe('IN_TRANSIT')->warehouse_id->toBeNull();
});

// Receive

it('receives everything: stock arrives at the destination and the transfer is completed', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5, 'BOX')]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    receive($transfer, [$line => new ReceivedLine(2)], $this->receiver);

    $header = DB::table('stock_transaction')->where('id', $transfer->id)->first();

    expect($header->status)->toBe('POSTED')
        ->and($header->received_by)->toBe($this->receiver->id)
        ->and($header->received_at)->not->toBeNull()
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(24.0)
        ->and($this->stock->balance('BEANS'))->toBe(36.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(0.0)
        ->and((float) DB::table('stock_transaction_line')->where('id', $line)->value('qty_received'))->toBe(24.0)
        ->and(DB::table('audit_log')->where('entity_id', $transfer->id)->orderBy('id')->pluck('action')->all())->toBe(['DISPATCH', 'RECEIVE']);
});

it('receives with the same expiry dates and serials that were sent', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
        $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2']),
    ]);
    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 6), $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])], $this->tripoli);
    [$yogurt, $phone] = array_values(lineIds($transfer));

    receive($transfer, [$yogurt => new ReceivedLine(6), $phone => new ReceivedLine(2, serials: ['SN-1', 'SN-2'])], $this->receiver);

    expect($this->stock->balance('YOGURT', $this->soon, $this->tripoli))->toBe(4.0)
        ->and($this->stock->balance('YOGURT', $this->late, $this->tripoli))->toBe(2.0)
        ->and(DB::table('serial_number')->where('warehouse_id', $this->tripoli->id)->where('status', 'IN_STOCK')->count())->toBe(2);
});

it('records a short receipt with its reason, and the shortage falls on the latest expiry', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
    ]);
    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 6)], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    receive($transfer, [$line => new ReceivedLine('3', 'DAMAGED_IN_TRANSIT')], $this->receiver);

    $row = DB::table('stock_transaction_line')->where('id', $line)->first();

    expect((float) $row->qty_received)->toBe(3.0)
        ->and($row->shortage_reason)->toBe('DAMAGED_IN_TRANSIT')
        ->and($this->stock->balance('YOGURT', $this->soon, $this->tripoli))->toBe(3.0)
        ->and($this->stock->balance('YOGURT', $this->late, $this->tripoli))->toBe(0.0)
        ->and($this->stock->inTransit('YOGURT'))->toBe(0.0)
        ->and(DB::table('stock_transaction')->where('id', $transfer->id)->value('status'))->toBe('POSTED');
});

it('requires a reason when less arrives', function (?string $reason) {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    try {
        receive($transfer, [$line => new ReceivedLine(8, $reason)], $this->receiver);
        $this->fail('Expected PostingRejected');
    } catch (PostingRejected $e) {
        expect($e->row)->toBe($line)->and($e->field)->toBe('reason');
    }

    expect($transfer->fresh()->status)->toBe('DISPATCHED')
        ->and($this->stock->inTransit('BEANS'))->toBe(10.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0);
})->with([null, 'LOST_AT_SEA']);

it('accepts receiving nothing on a line, with a reason', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    receive($transfer, [$line => new ReceivedLine(0, 'MISSING')], $this->receiver);

    expect($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(0.0)
        ->and($transfer->fresh()->status)->toBe('POSTED');
});

it('rejects receiving more than was sent, and saves nothing', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 5, 'BOX')]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    expect(fn () => receive($transfer, [$line => new ReceivedLine(3)], $this->receiver))
        ->toThrow(PostingRejected::class, __('transfers.errors.more_than_sent', ['qty' => '2 Box']));

    expect($transfer->fresh()->status)->toBe('DISPATCHED')
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0);
});

it('marks serials that did not arrive, and receives the rest', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);
    $transfer = $this->stock->dispatch([$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    receive($transfer, [$line => new ReceivedLine(1, 'MISSING', ['SN-2'])], $this->receiver);

    expect(DB::table('serial_number')->where('serial_no', 'SN-2')->first())->status->toBe('IN_STOCK')->warehouse_id->toBe($this->tripoli->id)
        ->and(DB::table('serial_number')->where('serial_no', 'SN-1')->value('status'))->toBe('DAMAGED');
});

it('only lets the destination warehouse receive', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    expect(fn () => receive($transfer, [$line => new ReceivedLine(10)], $this->stock->user))
        ->toThrow(NotAllowed::class, __('transfers.errors.not_destination'));
});

it('receives only once', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);
    [$line] = array_values(lineIds($transfer));
    receive($transfer, [$line => new ReceivedLine(10)], $this->receiver);

    expect(fn () => receive($transfer, [$line => new ReceivedLine(10)], $this->receiver))
        ->toThrow(AlreadyProcessed::class, 'already received by '.$this->receiver->full_name);

    expect($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(10.0);
});

// Cancel

it('lets the sender cancel while in transit: stock returns to the source', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: $this->soon),
        $this->stock->line('YOGURT', 10, expiry: $this->late),
        $this->stock->line('PHONE', 1, serials: ['SN-1']),
    ]);
    $transfer = $this->stock->dispatch([$this->stock->line('YOGURT', 6), $this->stock->line('PHONE', 1, serials: ['SN-1'])], $this->tripoli);

    app(PostingService::class)->cancel($transfer, $this->stock->user, 'Entered by mistake');

    expect($transfer->fresh()->status)->toBe('CANCELLED')
        ->and($this->stock->balance('YOGURT', $this->soon))->toBe(4.0)
        ->and($this->stock->balance('YOGURT', $this->late))->toBe(10.0)
        ->and($this->stock->inTransit('YOGURT'))->toBe(0.0)
        ->and(DB::table('serial_number')->where('serial_no', 'SN-1')->first())->status->toBe('IN_STOCK')->warehouse_id->toBe($this->stock->warehouse->id);
});

it('does not let the destination cancel, and nobody after receipt', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);

    expect(fn () => app(PostingService::class)->cancel($transfer, $this->receiver, 'Entered by mistake'))
        ->toThrow(NotAllowed::class, __('transfers.errors.only_sender_cancels'));

    [$line] = array_values(lineIds($transfer));
    receive($transfer, [$line => new ReceivedLine(10)], $this->receiver);

    expect(fn () => app(PostingService::class)->cancel($transfer, $this->stock->user, 'Entered by mistake'))
        ->toThrow(AlreadyProcessed::class, 'already received by '.$this->receiver->full_name);
});

it('can’t receive a cancelled transfer', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 10)], $this->tripoli);
    app(PostingService::class)->cancel($transfer, $this->stock->user, 'Entered by mistake');
    [$line] = array_values(lineIds($transfer));

    expect(fn () => receive($transfer, [$line => new ReceivedLine(10)], $this->receiver))
        ->toThrow(PostingRejected::class, __('transfers.errors.not_in_transit'));
});

it('refuses a decimal quantity for every type, and on receipt', function (string $type) {
    $line = $this->stock->line('BEANS', '1.5', 'BOX');

    expect(fn () => $type === 'TRANSFER' ? $this->stock->dispatch([$line], $this->tripoli) : $this->stock->post($type, [$line], reason: $type === 'DAMAGE' ? 'HANDLING' : 'STOCK_COUNT_VARIANCE'))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.number'));
})->with(['OPENING_BALANCE', 'IN', 'OUT', 'TRANSFER', 'DAMAGE', 'ADJUSTMENT']);

it('refuses a decimal quantity when a transfer is received', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 24)]);
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    [$line] = array_values(lineIds($transfer));

    expect(fn () => receive($transfer, [$line => new ReceivedLine('1.5', 'MISSING')], $this->receiver))
        ->toThrow(PostingRejected::class, __('transactions.form.errors.number'));
});
