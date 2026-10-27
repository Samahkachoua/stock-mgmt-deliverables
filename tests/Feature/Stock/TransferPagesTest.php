<?php

use App\Livewire\Reports\ReportPage;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\ReceiveTransfer;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->receiver = userWithRole('OPERATOR', $this->tripoli);
    $this->sender = userWithRole('OPERATOR', $this->stock->warehouse);
    $this->stock->post('IN', [$this->stock->line('BEANS', 5, 'BOX'), $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])]);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([])
        ->and(Stock::transitMismatches())->toBe([]);
});

/**
 * @return array<string, string> line id => line id, in line order
 */
function transferLines(StockTransaction $transfer): array
{
    return DB::table('stock_transaction_line')->where('transaction_id', $transfer->id)->orderBy('line_no')->pluck('id', 'id')->all();
}

// New transfer

it('sends a transfer from the form: From is the current warehouse, Save dispatches', function () {
    $form = Livewire::actingAs($this->sender)->test(TransactionForm::class, ['type' => 'TRANSFER'])
        ->call('chooseCustomer', $this->stock->customer->id);

    $form->assertSee(__('transfers.from_warehouse'))
        ->assertSee('Beirut Main')
        ->assertDontSee('<option value="'.$this->stock->warehouse->id.'"', false);

    [$row] = array_keys($form->get('lines'));
    $form->set('toWarehouse', $this->tripoli->id)
        ->set("lines.$row.item", $this->stock->id('BEANS'))
        ->set("lines.$row.unit", 'BOX')
        ->set("lines.$row.qty", '2')
        ->assertSee(__('transfers.send_to', ['warehouse' => 'Tripoli']))
        ->call('save')
        ->assertHasNoErrors();

    $transfer = StockTransaction::query()->where('transaction_type', 'TRANSFER')->sole();
    $form->assertRedirect(route('transfer.show', $transfer->doc_no));

    expect($transfer->status)->toBe('DISPATCHED')
        ->and($transfer->warehouse_id)->toBe($this->stock->warehouse->id)
        ->and($transfer->to_warehouse_id)->toBe($this->tripoli->id)
        ->and($this->stock->balance('BEANS'))->toBe(36.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(24.0);
});

it('shows the available quantity without what is in transit, and refuses more', function () {
    $this->stock->dispatch([$this->stock->line('BEANS', 4, 'BOX')], $this->tripoli);

    $form = Livewire::actingAs($this->sender)->test(TransactionForm::class, ['type' => 'TRANSFER'])
        ->call('chooseCustomer', $this->stock->customer->id)
        ->set('toWarehouse', $this->tripoli->id);
    [$row] = array_keys($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('BEANS'))->set("lines.$row.qty", '13')->call('save');

    expect(StockTransaction::query()->where('transaction_type', 'TRANSFER')->count())->toBe(1)
        ->and($this->stock->inTransit('BEANS'))->toBe(48.0);
});

// List

it('lists transfers sent and received, with an Incoming / Outgoing filter', function () {
    $outgoing = $this->stock->dispatch([$this->stock->line('BEANS', 1)], $this->tripoli);

    $this->stock->user->warehouses()->attach($this->tripoli);
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)], $this->stock->user->fresh(), $this->tripoli);
    $incoming = $this->stock->dispatch([$this->stock->line('BEANS', 2)], $this->stock->warehouse, $this->stock->user->fresh(), $this->tripoli);

    $unrelated = Warehouse::factory()->create();
    $this->stock->user->warehouses()->attach($unrelated);
    $elsewhere = $this->stock->dispatch([$this->stock->line('BEANS', 1)], $unrelated, $this->stock->user->fresh(), $this->tripoli);

    // The sender's warehouse: both directions; nothing between other warehouses.
    $list = Livewire::actingAs($this->sender)->test(TransactionList::class, ['type' => 'TRANSFER']);

    $list->assertSee($outgoing->doc_no)->assertSee($incoming->doc_no)->assertDontSee($elsewhere->doc_no)
        ->assertSee(__('transfers.direction.incoming'));
    $list->set('direction', 'incoming')->assertSee($incoming->doc_no)->assertDontSee($outgoing->doc_no);
    $list->set('direction', 'outgoing')->assertSee($outgoing->doc_no)->assertDontSee($incoming->doc_no);

    // The destination sees it too.
    Livewire::actingAs($this->receiver)->test(TransactionList::class, ['type' => 'TRANSFER'])
        ->assertSee($outgoing->doc_no);
});

// Details and receipt

it('lets a destination user receive, pre-filled with what was sent', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX'), $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2'])], $this->tripoli);
    [$beans, $phones] = array_values(transferLines($transfer));

    $this->actingAs($this->receiver)->get(route('transfer.show', $transfer->doc_no))
        ->assertOk()
        ->assertSee(__('transfers.receive.confirm'))
        ->assertSeeLivewire(ReceiveTransfer::class);

    $receive = Livewire::actingAs($this->receiver)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])
        ->assertSet("received.$beans", '2')
        ->assertSet("serials.$phones", ['SN-1', 'SN-2']);

    $receive->call('confirm')->assertHasNoErrors()->assertRedirect(route('transfer.show', $transfer->doc_no));

    expect($transfer->fresh()->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(24.0)
        ->and($this->stock->balance('PHONE', warehouse: $this->tripoli))->toBe(2.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(0.0);
});

it('needs a reason when less arrived, and records the shortage', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    [$beans] = array_values(transferLines($transfer));

    $receive = Livewire::actingAs($this->receiver)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])
        ->set("received.$beans", '1');

    $receive->assertSee(__('transfers.errors.reason_required'))
        ->assertSee(__('transfers.reasons.MISSING'))
        ->call('confirm');
    expect($transfer->fresh()->status)->toBe('DISPATCHED');

    $receive->set("reasons.$beans", 'MISSING')->call('confirm')->assertHasNoErrors();

    $line = DB::table('stock_transaction_line')->where('id', $beans)->first();
    expect($transfer->fresh()->status)->toBe('POSTED')
        ->and((float) $line->qty_received)->toBe(12.0)
        ->and($line->shortage_reason)->toBe('MISSING')
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(12.0);

    // Listed for managers, in the Transfer shortages report (the old address leads there).
    $this->actingAs($this->stock->user)->get(route('transfer.shortages'))->assertRedirect(route('reports.show', 'transfer-shortages'));
    $this->actingAs($this->stock->user)->get(route('reports.show', 'transfer-shortages'))
        ->assertOk()
        ->assertSee($transfer->doc_no)
        ->assertSee(__('transfers.reasons.MISSING'));
    $this->actingAs($this->receiver)->get(route('reports.show', 'transfer-shortages'))->assertForbidden();
    Livewire::actingAs($this->receiver)->test(ReportPage::class, ['report' => 'transfer-shortages'])->assertForbidden();
});

it('does not accept more than was sent', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    [$beans] = array_values(transferLines($transfer));

    Livewire::actingAs($this->receiver)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])
        ->set("received.$beans", '3')
        ->assertSee(e(__('transfers.errors.more_than_sent', ['qty' => '2 Box'])))
        ->call('confirm');

    expect($transfer->fresh()->status)->toBe('DISPATCHED')
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0);
});

it('shows the transfer read-only to a source-only user', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);

    $this->actingAs($this->sender)->get(route('transfer.show', $transfer->doc_no))
        ->assertOk()
        ->assertDontSeeLivewire(ReceiveTransfer::class)
        ->assertSee(__('transfers.receive.read_only', ['warehouse' => 'Tripoli']));

    Livewire::actingAs($this->sender)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])->assertForbidden();

    // A source manager may cancel it.
    $this->actingAs(userWithRole('MANAGER', $this->stock->warehouse))->get(route('transfer.show', $transfer->doc_no))
        ->assertDontSeeLivewire(ReceiveTransfer::class)
        ->assertSeeLivewire(CancelTransaction::class);
});

it('does not let the destination cancel, nor anyone after receipt', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    $manager = userWithRole('MANAGER', $this->tripoli);

    $this->actingAs($manager)->get(route('transfer.show', $transfer->doc_no))
        ->assertSeeLivewire(ReceiveTransfer::class)
        ->assertDontSeeLivewire(CancelTransaction::class);

    Livewire::actingAs($manager)->test(CancelTransaction::class, ['transactionId' => $transfer->id])
        ->call('ask')
        ->assertSet('problem', __('transfers.errors.only_sender_cancels'));
    expect($transfer->fresh()->status)->toBe('DISPATCHED');

    // After receipt, not even the sender.
    Livewire::actingAs($this->receiver)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])->call('confirm');
    $sourceManager = userWithRole('MANAGER', $this->stock->warehouse);

    $this->actingAs($sourceManager)->get(route('transfer.show', $transfer->doc_no))->assertDontSeeLivewire(CancelTransaction::class);
    Livewire::actingAs($sourceManager)->test(CancelTransaction::class, ['transactionId' => $transfer->id])
        ->call('ask')
        ->assertSet('problem', fn (string $problem) => str_contains($problem, 'already received by '.$this->receiver->full_name))
        ->assertDispatched('toast');
    expect($transfer->fresh()->status)->toBe('POSTED');
});

it('returns the stock when the sender cancels from the details page', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    $manager = userWithRole('MANAGER', $this->stock->warehouse);

    Livewire::actingAs($manager)->test(CancelTransaction::class, ['transactionId' => $transfer->id])
        ->call('ask')
        ->set('confirmReason', 'Entered by mistake')
        ->call('runConfirmed');

    expect($transfer->fresh()->status)->toBe('CANCELLED')
        ->and($this->stock->balance('BEANS'))->toBe(60.0)
        ->and($this->stock->inTransit('BEANS'))->toBe(0.0);

    $this->actingAs($manager)->get(route('transfer.show', $transfer->doc_no))
        ->assertDontSeeLivewire(CancelTransaction::class);
});

it('lets the operator who sent it cancel, without the general cancel permission', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    expect($this->sender->can('stock.in.cancel'))->toBeFalse();

    $this->actingAs($this->sender)->get(route('transfer.show', $transfer->doc_no))
        ->assertSeeLivewire(CancelTransaction::class);

    Livewire::actingAs($this->sender)->test(CancelTransaction::class, ['transactionId' => $transfer->id])
        ->call('ask')
        ->set('confirmReason', 'Entered by mistake')
        ->call('runConfirmed');

    expect($transfer->fresh()->status)->toBe('CANCELLED')
        ->and($this->stock->balance('BEANS'))->toBe(60.0);

    // Other documents still need their own cancel permission.
    $receipt = StockTransaction::query()->where('transaction_type', 'IN')->firstOrFail();
    $this->actingAs($this->sender)->get(route('receive.show', $receipt->doc_no))
        ->assertDontSeeLivewire(CancelTransaction::class);
    Livewire::actingAs($this->sender)->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->assertForbidden();
});

it('keeps a user of neither warehouse out', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    $outsider = userWithRole('ADMIN', Warehouse::factory()->create());

    $this->actingAs($outsider)->get(route('transfer.show', $transfer->doc_no))->assertNotFound();
    expect(fn () => Livewire::actingAs($outsider)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('renders the receipt in Arabic', function () {
    $transfer = $this->stock->dispatch([$this->stock->line('BEANS', 2, 'BOX')], $this->tripoli);
    $this->receiver->update(['preferred_lang' => 'ar']);

    $this->actingAs($this->receiver)->get(route('transfer.show', $transfer->doc_no))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('transfers.receive.confirm', [], 'ar'));
});
