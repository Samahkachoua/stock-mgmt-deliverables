<?php

use App\Livewire\Drafts\DraftForm;
use App\Livewire\Drafts\DraftList;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Stock\StockPage;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\DeliverNote;
use App\Livewire\Transactions\DispatchDelivery;
use App\Livewire\Transactions\TransactionList;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Services\Stock\Availability;
use App\Services\Stock\DeliveredLine;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\Exceptions\SerialProblem;
use App\Services\Stock\Exceptions\StockAlreadyUsed;
use App\Services\Stock\ItemCatalog;
use App\Services\Stock\PostingService;
use App\Services\Stock\Reconciliation;
use App\Services\Stock\Reservations;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * Orders reserve their stock; confirming one makes a Delivery note that keeps
 * it reserved (Confirmed), it is dispatched (driver, phone, truck) and then
 * delivered: only what was delivered leaves stock, the rest is available again.
 * Everything else that takes stock sees only what is not reserved.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    // 40 Box of beans.
    $this->stock->post('IN', [$this->stock->line('BEANS', 40, 'BOX')]);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([])
        ->and(app(Reconciliation::class)->mismatches())->toBe([]);
});

/** What the item search shows as available for BEANS, in base units (Piece). */
function availableBeans(Stock $stock, ?string $ownDraftId = null): float
{
    return ItemCatalog::item($stock->customer->id, $stock->id('BEANS'), $stock->warehouse->id, excludeExpired: true, ownDraftId: $ownDraftId)['available'];
}

/** Delivers a note, dispatching it first when it is still Confirmed. */
function deliver(StockTransaction $note, array $lines): void
{
    if ($note->fresh()->status === 'CONFIRMED') {
        app(PostingService::class)->dispatchDelivery($note, driver()->id, test()->stock->user);
    }

    app(PostingService::class)->deliver($note, $lines, test()->stock->user);
}

/** The note's lines in order, as id => line row. */
function noteLines(StockTransaction $note): array
{
    return DB::table('stock_transaction_line')->where('transaction_id', $note->id)->orderBy('line_no')->get()->keyBy('id')->all();
}

// Reserving

it('reserves an order, so the next order and every other document only see what is left', function () {
    $this->stock->order([$this->stock->line('BEANS', 20, 'BOX')]);

    // 40 Box in stock, 20 reserved: 20 available to anyone else.
    expect($this->stock->balance('BEANS'))->toBe(480.0)
        ->and($this->stock->reserved('BEANS'))->toBe(240.0)
        ->and(availableBeans($this->stock))->toBe(240.0);

    // Another order of 25 Box is refused, saying why; 10 Box is fine.
    expect(fn () => $this->stock->order([$this->stock->line('BEANS', 25, 'BOX')]))
        ->toThrow(InsufficientStock::class, __('stock.errors.not_enough_reserved', ['qty' => '20 Box', 'item' => 'Canned Beans', 'warehouse' => 'Beirut Main', 'reserved' => '20 Box']));
    $this->stock->order([$this->stock->line('BEANS', 10, 'BOX')]);
    expect(availableBeans($this->stock))->toBe(120.0);

    // A damage may take only what is not reserved.
    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 11, 'BOX')], $this->stock->user, reason: 'HANDLING'))
        ->toThrow(InsufficientStock::class);
    $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 10, 'BOX')], $this->stock->user, reason: 'HANDLING');

    expect($this->stock->balance('BEANS'))->toBe(360.0)
        ->and(availableBeans($this->stock))->toBe(0.0);

    // So may a transfer.
    expect(fn () => $this->stock->dispatch([$this->stock->line('BEANS', 1)], Warehouse::factory()->create()))
        ->toThrow(InsufficientStock::class);
});

it('refuses a count below what is reserved, and names the orders', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 20, 'BOX')]);

    expect(fn () => $this->stock->post('ADJUSTMENT', [$this->stock->line('BEANS', 15, 'BOX')], $this->stock->user, reason: 'STOCK_COUNT_VARIANCE'))
        ->toThrow(PostingRejected::class, __('stock.errors.count_below_reserved', ['qty' => '20 Box', 'orders' => $order->doc_no]));

    // Counting at least the reserved quantity is fine.
    $this->stock->post('ADJUSTMENT', [$this->stock->line('BEANS', 20, 'BOX')], $this->stock->user, reason: 'STOCK_COUNT_VARIANCE');

    expect($this->stock->balance('BEANS'))->toBe(240.0)
        ->and($this->stock->reserved('BEANS'))->toBe(240.0);
});

it('refuses to cancel a receipt whose stock is reserved', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $this->stock->order([$this->stock->line('BEANS', 40, 'BOX'), $this->stock->line('BEANS', 5)]);

    expect(fn () => app(PostingService::class)->cancel($receipt, $this->stock->user, 'Entered by mistake'))
        ->toThrow(StockAlreadyUsed::class);
});

it('re-reserves an edited order and lets it count its own stock as available', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 30, 'BOX')]);

    // Editing it: its own 30 Box count as available to it, not to others.
    expect(availableBeans($this->stock))->toBe(120.0)
        ->and(availableBeans($this->stock, $order->id))->toBe(480.0);

    $edited = app(DraftService::class)->save(new DraftInput(
        'OUT', $this->stock->customer->id, $order->customer_branch_id, $this->stock->warehouse->id, CarbonImmutable::today(), null,
        ['r1' => new DraftLineInput($this->stock->id('BEANS'), 'BOX', '35')],
        deliveryDate: CarbonImmutable::today(), priority: 'NORMAL',
    ), $this->stock->user, $order);

    expect($this->stock->reserved('BEANS'))->toBe(420.0);

    // Down to 10 Box: 25 Box are available again.
    app(DraftService::class)->save(new DraftInput(
        'OUT', $this->stock->customer->id, $order->customer_branch_id, $this->stock->warehouse->id, CarbonImmutable::today(), null,
        ['r1' => new DraftLineInput($this->stock->id('BEANS'), 'BOX', '10')],
        deliveryDate: CarbonImmutable::today(), priority: 'NORMAL',
    ), $this->stock->user, $edited->fresh());

    expect($this->stock->reserved('BEANS'))->toBe(120.0);

    // Cancelled: nothing reserved.
    app(DraftService::class)->cancel($order->fresh(), $this->stock->user, 'Customer changed their mind');

    expect($this->stock->reserved('BEANS'))->toBe(0.0)
        ->and(DB::table('stock_reservation')->count())->toBe(0);
});

it('shows the order form what its own order holds as available', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 40, 'BOX')]);

    $form = Livewire::actingAs($this->stock->user)->test(DraftForm::class, ['type' => 'OUT', 'reference' => $order->doc_no]);
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.qty", '40')->assertDontSee(e(__('transactions.form.errors.not_enough', ['qty' => '0 Box'])));
    $form->set("lines.$row.qty", '41')->assertSee(e(__('transactions.form.errors.not_enough', ['qty' => '40 Box'])));
});

it('reserves the serials on an order, so nothing else may take them', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);
    $order = $this->stock->order([$this->stock->line('PHONE', 1, serials: ['SN-1'])]);

    expect(app(Availability::class)->serials($this->stock->warehouse->id, $this->stock->id('PHONE')))->toBe(['SN-2'])
        ->and(app(Availability::class)->serials($this->stock->warehouse->id, $this->stock->id('PHONE'), ownDraftId: $order->id))->toBe(['SN-1', 'SN-2'])
        ->and(fn () => $this->stock->post('DAMAGE', [$this->stock->line('PHONE', 1, serials: ['SN-1'])], $this->stock->user, reason: 'HANDLING'))
        ->toThrow(SerialProblem::class, __('stock.errors.serial_reserved', ['serial' => 'SN-1']))
        ->and(fn () => $this->stock->order([$this->stock->line('PHONE', 1, serials: ['SN-1'])]))
        ->toThrow(SerialProblem::class);

    // A count must find it: it is on the shelf, held for the order.
    expect(fn () => $this->stock->post('ADJUSTMENT', [$this->stock->line('PHONE', 1, serials: ['SN-2'])], $this->stock->user, reason: 'STOCK_COUNT_VARIANCE'))
        ->toThrow(SerialProblem::class, __('stock.errors.serial_reserved_missing', ['serial' => 'SN-1']));

    // Delivered: issued, and no longer held.
    $note = $this->stock->deliver([$this->stock->line('PHONE', 1, serials: ['SN-2'])]);
    expect(DB::table('serial_number')->where('serial_no', 'SN-2')->value('status'))->toBe('ISSUED')
        ->and($note->status)->toBe('POSTED');
});

// Confirmed, dispatched, delivered

it('confirms an order into a delivery note that holds the stock without moving it', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 20, 'BOX')]);

    expect($note->status)->toBe('CONFIRMED')
        ->and($note->doc_no)->toStartWith('DN-')
        ->and(DB::table('stock_movement')->where('transaction_id', $note->id)->exists())->toBeFalse()
        ->and($this->stock->balance('BEANS'))->toBe(480.0)
        ->and($this->stock->reserved('BEANS'))->toBe(240.0)
        ->and(DB::table('stock_reservation')->whereNotNull('draft_line_id')->exists())->toBeFalse()
        ->and(DB::table('stock_reservation')->whereIn('line_id', array_keys(noteLines($note)))->sum('qty_base'))->toEqual(240);
});

it('dispatches with a driver, keeping a copy of their details, changing no stock', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);
    $driver = driver(['name' => 'Nour', 'phone' => '+961 70 123 456', 'truck_no' => 'B 123456']);

    app(PostingService::class)->dispatchDelivery($note, $driver->id, $this->stock->user);

    // Editing the driver later doesn't change the note.
    $driver->update(['phone' => '+961 71 000 000', 'truck_no' => 'NEW-1']);

    $header = DB::table('stock_transaction')->where('id', $note->id)->first();
    expect($header->status)->toBe('DISPATCHED')
        ->and($header->driver_id)->toBe($driver->id)
        ->and($header->driver_name)->toBe('Nour')
        ->and($header->driver_phone)->toBe('+961 70 123 456')
        ->and($header->truck_no)->toBe('B 123456')
        ->and($header->driver_licence_no)->toBe($driver->licence_no)
        ->and($header->driver_national_id)->toBe($driver->national_id)
        ->and($header->dispatched_by)->toBe($this->stock->user->id)
        ->and($this->stock->reserved('BEANS'))->toBe(24.0)
        ->and($this->stock->balance('BEANS'))->toBe(480.0);

    expect(fn () => app(PostingService::class)->dispatchDelivery($note, driver()->id, $this->stock->user))
        ->toThrow(AlreadyProcessed::class);
});

it('needs a driver to dispatch', function (?string $driverId) {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);

    expect(fn () => app(PostingService::class)->dispatchDelivery($note, $driverId, $this->stock->user))
        ->toThrow(PostingRejected::class, __('deliveries.errors.driver_required'));

    expect(DB::table('stock_transaction')->where('id', $note->id)->value('status'))->toBe('CONFIRMED');
})->with(['none' => [null], 'empty' => [''], 'not an id' => ['abc']]);

it('refuses a driver that no longer exists', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);
    $driver = driver();
    $driver->delete();

    expect(fn () => app(PostingService::class)->dispatchDelivery($note, $driver->id, $this->stock->user))
        ->toThrow(PostingRejected::class, __('deliveries.errors.driver_not_found'));
});

it('delivers in full: the stock leaves and nothing stays reserved', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 20, 'BOX')]);
    deliver($note, Stock::deliveredInFull($note));

    $header = DB::table('stock_transaction')->where('id', $note->id)->first();
    expect($header->status)->toBe('POSTED')
        ->and($header->delivered_at)->not->toBeNull()
        ->and($this->stock->balance('BEANS'))->toBe(240.0)
        ->and($this->stock->reserved('BEANS'))->toBe(0.0)
        ->and((float) collect(noteLines($note))->first()->qty_received)->toBe(240.0);

    expect(fn () => deliver($note, Stock::deliveredInFull($note)))->toThrow(AlreadyProcessed::class);
});

it('delivers less with a reason: only that leaves stock, the rest is available again', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 20, 'BOX'), $this->stock->line('BEANS', 5)]);
    [$boxes, $pieces] = array_keys(noteLines($note));

    // Less needs a reason.
    expect(fn () => deliver($note, [$boxes => new DeliveredLine('15'), $pieces => new DeliveredLine('0', 'REFUSED')]))
        ->toThrow(PostingRejected::class, __('deliveries.errors.reason_required'));
    // Never more than ordered.
    expect(fn () => deliver($note, [$boxes => new DeliveredLine('21'), $pieces => new DeliveredLine('5')]))
        ->toThrow(PostingRejected::class);

    deliver($note, [$boxes => new DeliveredLine('15', 'NOT_AVAILABLE'), $pieces => new DeliveredLine('0', 'REFUSED')]);

    expect($this->stock->balance('BEANS'))->toBe(300.0)
        ->and($this->stock->reserved('BEANS'))->toBe(0.0)
        ->and(availableBeans($this->stock))->toBe(300.0)
        ->and(DB::table('stock_movement')->where('transaction_id', $note->id)->count())->toBe(1);

    // The difference shows on the report.
    $report = Livewire::actingAs($this->stock->user)->test(ReportPage::class, ['report' => 'delivery-differences']);
    $rows = collect($report->viewData('rows')->items())->pluck('cells');

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('reason')->all())->toBe([__('deliveries.reasons.NOT_AVAILABLE'), __('deliveries.reasons.REFUSED')])
        ->and($rows[0]['short']['value'])->toBe(60.0);
});

it('delivers another item instead, from stock that is not reserved', function () {
    $late = now()->addDays(60)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 3, 'BOX', expiry: $late)]);
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);
    [$line] = array_keys(noteLines($note));
    $yogurt = $this->stock->line('YOGURT', 2, 'BOX', expiry: $late);

    // "Replaced" needs the item; an item needs "Replaced".
    expect(fn () => deliver($note, [$line => new DeliveredLine('0', 'REPLACED')]))->toThrow(PostingRejected::class, __('deliveries.errors.instead_required'))
        ->and(fn () => deliver($note, [$line => new DeliveredLine('0', 'DAMAGED', instead: $yogurt)]))->toThrow(PostingRejected::class);

    deliver($note, [$line => new DeliveredLine('0', 'REPLACED', instead: $yogurt)]);

    $lines = array_values(noteLines($note));
    expect($lines)->toHaveCount(2)
        ->and((string) $lines[1]->replaces_line_id)->toBe((string) $line)
        ->and((float) $lines[1]->qty_received)->toBe(12.0)
        ->and($this->stock->balance('BEANS'))->toBe(480.0)
        ->and($this->stock->balance('YOGURT'))->toBe(6.0);

    $this->actingAs($this->stock->user)->get(route('delivery-note.show', $note->doc_no))
        ->assertOk()
        ->assertSee(__('deliveries.details.instead_of', ['item' => 'Canned Beans']))
        ->assertSee(__('deliveries.reasons.REPLACED'));
});

it('cancels a delivery note before delivery by releasing its stock, and after by putting it back', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 20, 'BOX')]);

    app(PostingService::class)->cancel($note, $this->stock->user, 'Customer cancelled');

    expect($note->fresh()->status)->toBe('CANCELLED')
        ->and($this->stock->reserved('BEANS'))->toBe(0.0)
        ->and($this->stock->balance('BEANS'))->toBe(480.0);

    $delivered = $this->stock->deliver([$this->stock->line('BEANS', 5, 'BOX')]);
    app(PostingService::class)->cancel($delivered, $this->stock->user, 'Delivered by mistake');

    expect($this->stock->balance('BEANS'))->toBe(480.0)
        ->and($delivered->fresh()->status)->toBe('CANCELLED');
});

it('finds a reserved quantity that no longer matches its reservations', function () {
    $this->stock->order([$this->stock->line('BEANS', 1, 'BOX')]);
    DB::table('stock_balance')->where('product_id', $this->stock->id('BEANS'))->update(['qty_reserved' => 5]);

    $mismatches = app(Reconciliation::class)->mismatches();
    expect($mismatches)->toHaveCount(1)
        ->and($mismatches[0])->toMatchArray(['item' => 'BEANS', 'balance' => 5.0, 'movements' => 12.0, 'reserved' => true]);

    DB::table('stock_balance')->where('product_id', $this->stock->id('BEANS'))->update(['qty_reserved' => 12]);
});

it('lists who holds an item', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 1, 'BOX')]);
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);

    expect(app(Reservations::class)->holders($this->stock->warehouse->id, $this->stock->customer->id, $this->stock->id('BEANS')))
        ->toBe([
            ['reference' => $order->doc_no, 'type' => 'OUT', 'draft' => true, 'qty' => 12.0],
            ['reference' => $note->doc_no, 'type' => 'OUT', 'draft' => false, 'qty' => 24.0],
        ]);
});

// Screens

it('has no New page for delivery notes', function () {
    expect(Route::has('delivery-note.create'))->toBeFalse();

    $this->actingAs($this->stock->user)->get(route('delivery-note'))
        ->assertOk()
        ->assertDontSee(__('transactions.types.delivery_note.new'));
});

it('takes a delivery note through its steps on the details page', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 20, 'BOX')]);

    $this->actingAs($this->stock->user)->get(route('delivery-note.show', $note->doc_no))
        ->assertOk()
        ->assertSee(__('transactions.status.confirmed'))
        ->assertSee(__('deliveries.dispatch.button'))
        ->assertSee(__('deliveries.deliver.dispatch_first'))
        ->assertDontSee('transactions.deliver-note', false)
        ->assertSee(__('deliveries.deliver.reserved_notice'));

    $driver = driver(['name' => 'Nour', 'truck_no' => 'B 123456']);

    Livewire::actingAs($this->stock->user)->test(DispatchDelivery::class, ['transactionId' => $note->id])
        ->call('show')
        ->assertSee('Nour · B 123456')
        ->call('dispatchNote')
        ->assertHasErrors('driverId')
        ->set('driverId', $driver->id)
        ->assertSee($driver->licence_no)
        ->assertSee($driver->national_id)
        ->call('dispatchNote')
        ->assertRedirect(route('delivery-note.show', $note->doc_no));

    expect(session('toasts')[0]['message'])->toBe(__('feedback.delivery_dispatched', ['reference' => $note->doc_no]));

    $this->get(route('delivery-note.show', $note->doc_no))
        ->assertSee(__('transactions.status.dispatched'))
        ->assertSee('Nour')
        ->assertSee('B 123456')
        ->assertDontSee('transactions.dispatch-delivery', false);

    [$line] = array_keys(noteLines($note));
    $form = Livewire::actingAs($this->stock->user)->test(DeliverNote::class, ['transactionId' => $note->id])
        ->assertSet("delivered.$line", '20')
        ->set("delivered.$line", '18')
        ->assertSee(__('deliveries.errors.reason_required'));

    $form->set("reasons.$line", 'DAMAGED')->call('confirm')->assertRedirect(route('delivery-note.show', $note->doc_no));

    expect($note->fresh()->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(264.0)
        ->and($this->stock->reserved('BEANS'))->toBe(0.0)
        ->and(session('toasts')[0]['type'])->toBe('warning');

    $this->get(route('delivery-note.show', $note->doc_no))
        ->assertSee(__('transactions.status.delivered'))
        ->assertSee(__('deliveries.deliver.ordered'))
        ->assertSee(__('deliveries.deliver.delivered'))
        ->assertDontSee(__('deliveries.deliver.confirm'));
});

it('asks before cancelling a confirmed delivery note, saying its stock becomes available', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);

    Livewire::actingAs($this->stock->user)->test(CancelTransaction::class, ['transactionId' => $note->id])
        ->call('ask')
        ->assertSet('confirm.body', __('confirm.cancel.releases', ['stock' => '2 Box of Canned Beans', 'warehouse' => 'Beirut Main']))
        ->set('confirmReason', 'Customer cancelled')
        ->call('runConfirmed');

    expect(session('toasts')[0]['message'])->toBe(__('feedback.delivery_cancelled', ['reference' => $note->doc_no]))
        ->and($this->stock->reserved('BEANS'))->toBe(0.0);
});

it('shows In stock, Reserved and Available on the Stock page', function () {
    $this->stock->order([$this->stock->line('BEANS', 15, 'BOX')]);

    $page = Livewire::actingAs($this->stock->user)->test(StockPage::class);
    $row = collect($page->viewData('rows')->items())->firstWhere('cells.code.text', 'BEANS')['cells'];

    expect($row['qty']['value'])->toBe(480.0)
        ->and($row['reserved']['value'])->toBe(180.0)
        ->and($row['available']['value'])->toBe(300.0);

    $page->assertSee(__('reports.columns.reserved'))->assertSee(__('reports.columns.available'));
});

it('shows on the item page what is reserved, what is available and for which documents', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 5, 'BOX')]);

    $this->actingAs($this->stock->user)->get(route('stock.item', ['product' => $this->stock->id('BEANS'), 'warehouse' => $this->stock->warehouse->id]))
        ->assertOk()
        ->assertSee(__('reports.item.reserved_by'))
        ->assertSee($order->doc_no)
        ->assertSee('60 Piece')
        ->assertSee('420 Piece');
});

it('marks a delivery note delivered short on the list', function () {
    $full = $this->stock->deliver([$this->stock->line('BEANS', 1, 'BOX')]);
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);
    [$line] = array_keys(noteLines($note));
    deliver($note, [$line => new DeliveredLine('1', 'DAMAGED')]);

    $rows = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'OUT'])
        ->call('loadRows')
        ->assertSee(__('deliveries.list.short'))
        ->viewData('rows');

    expect(collect($rows->items())->pluck('short', 'reference')->sortKeys()->all())->toBe(collect([$note->doc_no => true, $full->doc_no => false])->sortKeys()->all());
});

it('refuses to confirm the delivery of a note that has not been dispatched', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);

    expect(fn () => app(PostingService::class)->deliver($note, Stock::deliveredInFull($note), $this->stock->user))
        ->toThrow(PostingRejected::class, __('deliveries.errors.not_dispatched'));

    expect($note->fresh()->status)->toBe('CONFIRMED')
        ->and($this->stock->reserved('BEANS'))->toBe(24.0)
        ->and($this->stock->balance('BEANS'))->toBe(480.0);

    // Dispatched: now it can be delivered.
    app(PostingService::class)->dispatchDelivery($note, driver()->id, $this->stock->user);
    app(PostingService::class)->deliver($note, Stock::deliveredInFull($note), $this->stock->user);

    expect($note->fresh()->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(456.0);
});

it('shows the total quantity of each delivery note on the list: ordered, then what was delivered', function () {
    $open = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX'), $this->stock->line('BEANS', 5)]);
    $short = $this->stock->confirmOrder([$this->stock->line('BEANS', 2, 'BOX')]);
    [$line] = array_keys(noteLines($short));
    deliver($short, [$line => new DeliveredLine('1', 'DAMAGED')]);
    $piece = (string) DB::table('unit')->where('id', DB::table('product')->where('id', $this->stock->id('BEANS'))->value('base_unit_id'))->value('code');

    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'OUT'])
        ->call('loadRows')
        ->assertSee(__('transactions.list.total'));

    expect(collect($list->viewData('rows')->items())->pluck('total', 'reference')->all())
        ->toMatchArray([$open->doc_no => Format::qtyUnit(29, $piece), $short->doc_no => Format::qtyUnit(12, $piece)]);

    // The footer adds up every note the filters match.
    $list->assertSee(trans_choice('transactions.list.footer_total', 2, ['count' => 2]))
        ->assertViewHas('total', Format::qtyUnit(41, $piece));
    $list->set('search', $short->doc_no)->assertViewHas('total', Format::qtyUnit(12, $piece));
});

it('shows the total quantity ordered on the order list, with a footer', function () {
    $this->stock->order([$this->stock->line('BEANS', 2, 'BOX'), $this->stock->line('BEANS', 5)]);
    $this->stock->order([$this->stock->line('BEANS', 1, 'BOX')]);
    $piece = (string) DB::table('unit')->where('id', DB::table('product')->where('id', $this->stock->id('BEANS'))->value('base_unit_id'))->value('code');

    $list = Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'OUT'])
        ->call('loadRows')
        ->assertSee(__('transactions.list.total'))
        ->assertViewHas('total', Format::qtyUnit(41, $piece));

    expect(collect($list->viewData('rows')->items())->pluck('total')->sort()->values()->all())
        ->toBe([Format::qtyUnit(12, $piece), Format::qtyUnit(29, $piece)]);

    // Draft receipts have neither.
    Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->assertViewHas('total', null)
        ->assertDontSee(__('transactions.list.total'));
});
