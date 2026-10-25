<?php

use App\Livewire\ItemPicker;
use App\Livewire\Transactions\TransactionForm;
use App\Models\CustomerBranch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\ItemCatalog;
use App\Services\Stock\PostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * Service items (CLAUDE.md business rule 13): lines on receipts and delivery
 * notes (and their drafts) that never move stock; refused everywhere else.
 */

beforeEach(function () {
    $this->stock = Stock::create();

    $hour = Unit::firstOrCreate(['code' => 'HR'], ['name' => 'Hour']);
    $service = Product::create([
        'customer_id' => $this->stock->customer->id, 'sku' => 'PACK', 'name_en' => 'Packing',
        'base_unit_id' => $hour->id, 'is_service' => true,
    ]);
    $service->units()->create(['unit_id' => $hour->id, 'factor_to_base' => 1, 'is_default' => true]);
    $this->stock->items['PACK'] = $service->load('units.unit');
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

it('saves a service line on a receipt and a delivery note without any stock', function (string $type) {
    if ($type === 'OUT') {
        $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    }

    $document = $this->stock->post($type, [
        $this->stock->line('BEANS', 4),
        $this->stock->line('PACK', 3, 'HR'),
    ]);

    $lines = DB::table('stock_transaction_line')->where('transaction_id', $document->id)->orderBy('line_no')->get();

    expect($document->status)->toBe('POSTED')
        ->and($lines)->toHaveCount(2)
        ->and($lines[1]->product_id)->toBe($this->stock->id('PACK'))
        ->and((float) $lines[1]->qty_base)->toBe(3.0)
        ->and(DB::table('stock_movement')->where('product_id', $this->stock->id('PACK'))->exists())->toBeFalse()
        ->and(DB::table('stock_balance')->where('product_id', $this->stock->id('PACK'))->exists())->toBeFalse()
        ->and($this->stock->balance('BEANS'))->toBe($type === 'IN' ? 4.0 : 6.0);

    // Cancelling reverses the stock line only.
    app(PostingService::class)->cancel($document, $this->stock->user, 'Entered twice');

    expect($this->stock->balance('BEANS'))->toBe($type === 'IN' ? 0.0 : 10.0)
        ->and(DB::table('stock_movement')->where('product_id', $this->stock->id('PACK'))->exists())->toBeFalse();
})->with(['IN', 'OUT']);

it('delivers a service with nothing in stock', function () {
    $document = $this->stock->post('OUT', [$this->stock->line('PACK', 2, 'HR')]);

    expect($document->status)->toBe('POSTED')
        ->and(DB::table('stock_transaction_line')->where('transaction_id', $document->id)->count())->toBe(1);
});

it('refuses a service on types that move stock only', function (string $type, ?string $reason) {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $before = DB::table('stock_transaction')->count();

    $call = $type === 'TRANSFER'
        ? fn () => $this->stock->dispatch([$this->stock->line('PACK', 1, 'HR')], Warehouse::factory()->create())
        : fn () => $this->stock->post($type, [$this->stock->line('PACK', 1, 'HR')], by: $this->stock->user, reason: $reason);

    try {
        $call();
        $this->fail('A service line was accepted on '.$type);
    } catch (PostingRejected $e) {
        expect($e->key)->toBe('transactions.form.errors.service_not_here')
            ->and($e->row)->toBe('r1')
            ->and($e->field)->toBe('item')
            ->and($e->getMessage())->toContain('Packing');
    }

    expect(DB::table('stock_transaction')->count())->toBe($before);
})->with([
    ['TRANSFER', null],
    ['ADJUSTMENT', 'STOCK_COUNT_VARIANCE'],
    ['DAMAGE', 'HANDLING'],
    ['OPENING_BALANCE', null],
]);

it('saves and confirms an order with a service line', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $branch = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);

    $draft = app(DraftService::class)->save(new DraftInput(
        'OUT', $this->stock->customer->id, $branch->id, $this->stock->warehouse->id, CarbonImmutable::today(), 'PO 1', [
            'r1' => new DraftLineInput($this->stock->id('BEANS'), 'PC', 2),
            'r2' => new DraftLineInput($this->stock->id('PACK'), 'HR', 5),
        ]), $this->stock->user);

    app(PostingService::class)->confirmDraft($draft, $this->stock->user);

    $deliveryNote = DB::table('stock_transaction')->where('draft_id', $draft->id)->first();

    expect(DB::table('stock_transaction_line')->where('transaction_id', $deliveryNote->id)->count())->toBe(2)
        ->and($this->stock->balance('BEANS'))->toBe(8.0)
        ->and(DB::table('stock_movement')->where('product_id', $this->stock->id('PACK'))->exists())->toBeFalse();
});

it('offers services in the item picker of receipts and delivery notes only', function () {
    $search = fn (bool $services) => array_column(ItemCatalog::search($this->stock->customer->id, '', $this->stock->warehouse->id, withServices: $services), 'code');

    expect($search(false))->not->toContain('PACK')->toContain('BEANS')
        ->and($search(true))->toContain('PACK')
        ->and(ItemCatalog::item($this->stock->customer->id, $this->stock->id('PACK'), $this->stock->warehouse->id))->toBeNull();

    Livewire::actingAs($this->stock->user)
        ->test(ItemPicker::class, ['customer' => $this->stock->customer->id, 'services' => true])
        ->set('search', 'pack')
        ->assertSee('Packing')
        ->assertSee(__('transactions.picker.service'));
});

it('lets a delivery note row of a service go above the stock, with a service hint', function () {
    $form = Livewire::actingAs($this->stock->user)->test(TransactionForm::class, ['type' => 'OUT'])
        ->call('chooseCustomer', $this->stock->customer->id);
    $row = array_key_first($form->get('lines'));

    $form->set("lines.$row.item", $this->stock->id('PACK'))
        ->assertSet("lines.$row.unit", 'HR')
        ->set("lines.$row.qty", '4')
        ->assertSee(__('transactions.form.service_hint'))
        ->assertDontSee(__('transactions.form.errors.not_enough', ['qty' => '0 Hour']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(DB::table('stock_transaction_line')->where('product_id', $this->stock->id('PACK'))->count())->toBe(1);
});

it('does not take a service on a transfer form row', function () {
    $form = Livewire::actingAs($this->stock->user)->test(TransactionForm::class, ['type' => 'TRANSFER'])
        ->call('chooseCustomer', $this->stock->customer->id);
    $row = array_key_first($form->get('lines'));

    // Not one of the items this type offers: no unit, so the row can't be saved.
    $form->set("lines.$row.item", $this->stock->id('PACK'))->assertSet("lines.$row.unit", '');
});

// Database safety nets

it('never posts a movement for a service in the database', function () {
    $transaction = $this->stock->post('IN', [$this->stock->line('PACK', 1, 'HR')]);
    $line = DB::table('stock_transaction_line')->where('transaction_id', $transaction->id)->value('id');

    expect(fn () => DB::transaction(fn () => DB::select('SELECT fn_post_movement(?, ?, ?, ?, ?, NULL, ?::movement_direction, ?)', [
        $transaction->id, $line, $this->stock->warehouse->id, $this->stock->customer->id, $this->stock->id('PACK'), 'IN', 1,
    ])))->toThrow(QueryException::class, 'Service item has no stock');
});

it('keeps a service untracked and on its base unit only, in the database', function () {
    $service = $this->stock->items['PACK'];

    expect(fn () => DB::transaction(fn () => $service->update(['track_expiry' => true])))
        ->toThrow(QueryException::class, 'ck_product_service_untracked');

    expect(fn () => DB::transaction(fn () => $service->units()->create(['unit_id' => Unit::where('code', 'BOX')->value('id'), 'factor_to_base' => 12])))
        ->toThrow(QueryException::class, 'Services only support their base unit');
});
