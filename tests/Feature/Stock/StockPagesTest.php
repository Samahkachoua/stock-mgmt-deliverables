<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\TransactionList;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
});

// Lists

it('lists the documents of the current warehouse with their totals', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX'), $this->stock->line('BEANS', 4)]);

    $other = Warehouse::factory()->create(['name' => 'Tripoli']);
    $this->stock->user->warehouses()->attach($other);
    $elsewhere = $this->stock->post('IN', [$this->stock->line('BEANS', 1)], $this->stock->user->fresh(), $other);

    $this->actingAs($this->stock->user)
        ->withSession([EnsureCurrentWarehouse::SESSION_KEY => $this->stock->warehouse->id])
        ->get(route('receive'))
        ->assertOk()
        ->assertSee($receipt->doc_no)
        ->assertSee('2 items')
        ->assertSee('40 units')
        ->assertSee('Acme Foods')
        ->assertDontSee($elsewhere->doc_no);

    $this->actingAs($this->stock->user->fresh())
        ->withSession([EnsureCurrentWarehouse::SESSION_KEY => $other->id])
        ->get(route('receive'))
        ->assertSee($elsewhere->doc_no)
        ->assertDontSee($receipt->doc_no);
});

it('filters real documents by reference, customer, status and date', function () {
    $first = $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $second = $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);
    app(PostingService::class)->cancel($second, $this->stock->user, 'Entered by mistake');
    $old = app(PostingService::class)->post(
        new Document('IN', $this->stock->warehouse->id, $this->stock->customer->id, CarbonImmutable::today()->subDays(60), null, ['r1' => new DocumentLine($this->stock->id('BEANS'), 'PC', 1)]),
        $this->stock->user,
    );

    session([EnsureCurrentWarehouse::SESSION_KEY => $this->stock->warehouse->id]);
    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'IN']);

    // No dates: everything; "Date from" leaves the older document out.
    $list->assertSee($first->doc_no)->assertSee($second->doc_no)->assertSee($old->doc_no);
    $list->set('dateFrom', CarbonImmutable::today()->subDays(30)->toDateString())->assertSee($first->doc_no)->assertDontSee($old->doc_no);
    $list->set('dateFrom', '')->assertSee($old->doc_no);
    $list->set('search', strtolower($first->doc_no))->assertSee($first->doc_no)->assertDontSee($second->doc_no);
    $list->set('search', '')->set('status', 'CANCELLED')->assertSee($second->doc_no)->assertDontSee($first->doc_no);
    $list->set('status', '')->set('customer', Customer::factory()->create()->id)->assertSee(__('transactions.list.no_matches_title'));
});

it('does not show documents of another warehouse, even by URL', function () {
    $other = Warehouse::factory()->create();
    $this->stock->user->warehouses()->attach($other);
    $elsewhere = $this->stock->post('IN', [$this->stock->line('BEANS', 1)], $this->stock->user->fresh(), $other);

    $operator = userWithRole('OPERATOR', $this->stock->warehouse);

    $this->actingAs($operator)->get(route('receive.show', $elsewhere->doc_no))->assertNotFound();
});

it('shows a customer portal user only their own customer’s documents', function () {
    $mine = $this->stock->post('IN', [$this->stock->line('BEANS', 1)]);

    $otherCustomer = Customer::factory()->create();
    $foreign = Product::create(['customer_id' => $otherCustomer->id, 'sku' => 'F', 'name_en' => 'Foreign', 'base_unit_id' => $this->stock->items['BEANS']->base_unit_id]);
    $foreign->units()->create(['unit_id' => $foreign->base_unit_id, 'factor_to_base' => 1, 'is_default' => true]);
    $theirs = app(PostingService::class)->post(
        new Document('IN', $this->stock->warehouse->id, $otherCustomer->id, CarbonImmutable::today(), null, ['r1' => new DocumentLine($foreign->id, 'PC', 1)]),
        $this->stock->user,
    );

    $portal = userWithRole('CUSTOMER', $this->stock->warehouse);
    $portal->forceFill(['is_customer_user' => true, 'customer_id' => $this->stock->customer->id])->save();

    $this->actingAs($portal)->get(route('receive'))->assertSee($mine->doc_no)->assertDontSee($theirs->doc_no);
    $this->actingAs($portal)->get(route('receive.show', $mine->doc_no))->assertOk();
    $this->actingAs($portal)->get(route('receive.show', $theirs->doc_no))->assertNotFound();
});

// Details

it('shows lines in the unit entered with their base quantity, and the activity', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX')]);

    $this->actingAs($this->stock->user)->get(route('receive.show', $receipt->doc_no))
        ->assertOk()
        ->assertSee('Canned Beans')
        ->assertSee('36 Piece')
        ->assertSee(__('transactions.status.completed'))
        ->assertSee(__('transactions.activity.posted', ['name' => '<bdi>'.e($this->stock->user->full_name).'</bdi>']), false);
});

it('shows which expiry dates an issue took, and the serial numbers', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: now()->addDays(5)->toDateString()),
        $this->stock->line('YOGURT', 10, expiry: now()->addDays(50)->toDateString()),
        $this->stock->line('PHONE', 2, serials: ['SN-1', 'SN-2']),
    ]);
    $issue = $this->stock->post('OUT', [$this->stock->line('YOGURT', 6), $this->stock->line('PHONE', 1, serials: ['SN-2'])]);

    $this->actingAs($this->stock->user)->get(route('issue.show', $issue->doc_no))
        ->assertOk()
        ->assertSee(__('transactions.details.from_expiries', ['dates' => Format::date(now()->addDays(5)).' (4 Piece), '.Format::date(now()->addDays(50)).' (2 Piece)']))
        ->assertSee(__('transactions.details.serials', ['serials' => 'SN-2']));
});

it('shows the expiry date chosen on an issue', function () {
    $this->stock->post('IN', [
        $this->stock->line('YOGURT', 4, expiry: now()->addDays(5)->toDateString()),
        $this->stock->line('YOGURT', 10, expiry: now()->addDays(50)->toDateString()),
    ]);
    $issue = $this->stock->post('OUT', [$this->stock->line('YOGURT', 6, expiry: now()->addDays(50)->toDateString())]);

    $this->actingAs($this->stock->user)->get(route('issue.show', $issue->doc_no))
        ->assertOk()
        ->assertSee(__('transactions.lines.expiry'))
        ->assertSee(Format::date(now()->addDays(50)))
        ->assertDontSee(Format::date(now()->addDays(5)));
});

it('keeps posted documents read-only', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);

    $html = $this->actingAs($this->stock->user)->get(route('receive.show', $receipt->doc_no))->getContent();
    preg_match('#<main.*</main>#s', $html, $main);

    // Nothing to edit in the page body (the top bar has the warehouse switcher).
    expect($main[0])->not->toContain('wire:model')->not->toContain('<input');
});

// Cancel

it('cancels a posted document from its details page', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);

    $this->actingAs($this->stock->user)->get(route('receive.show', $receipt->doc_no))
        ->assertSee(__('stock.cancel.button'));

    Livewire::actingAs($this->stock->user)
        ->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->assertSee(__('confirm.cancel.title', ['type' => 'receipt', 'reference' => $receipt->doc_no]))
        ->set('confirmReason', 'Entered by mistake')
        ->call('runConfirmed')
        ->assertRedirect(route('receive.show', $receipt->doc_no));

    expect($receipt->fresh()->status)->toBe('CANCELLED')
        ->and($this->stock->balance('BEANS'))->toBe(0.0)
        ->and(Stock::ledgerMismatches())->toBe([]);

    $this->actingAs($this->stock->user)->get(route('receive.show', $receipt->doc_no))
        ->assertSee(__('transactions.status.cancelled'))
        ->assertDontSee(__('stock.cancel.button'));
});

it('explains when a document can’t be cancelled because its stock was used', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);
    $this->stock->post('OUT', [$this->stock->line('BEANS', 4)]);

    Livewire::actingAs($this->stock->user)
        ->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->set('confirmReason', 'Entered by mistake')
        ->call('runConfirmed')
        ->assertNoRedirect()
        ->assertSee(__('stock.errors.already_used', ['item' => 'Canned Beans', 'received' => '10 Piece', 'left' => '6 Piece']));

    expect($receipt->fresh()->status)->toBe('POSTED');
});

it('only lets users with transaction.cancel cancel', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);

    $this->actingAs($operator)->get(route('receive.show', $receipt->doc_no))->assertDontSee(__('stock.cancel.button'));

    Livewire::actingAs($operator)
        ->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->assertForbidden();

    expect($receipt->fresh()->status)->toBe('POSTED');
});

it('does not let a manager of another warehouse cancel', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 3)]);
    $outsider = userWithRole('MANAGER');

    // The document is outside the manager's warehouses, so it isn't found at all.
    expect(fn () => Livewire::actingAs($outsider)
        ->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask'))->toThrow(ModelNotFoundException::class);

    expect($receipt->fresh()->status)->toBe('POSTED')
        ->and(DB::table('stock_movement')->where('transaction_id', $receipt->id)->count())->toBe(1);
});
