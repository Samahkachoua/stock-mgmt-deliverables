<?php

use App\Livewire\GlobalSearch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\ListState;
use App\Support\Search\GlobalSearch as Search;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * The top bar search: documents, items, customers, serials and batches,
 * limited to what the user may open and to their warehouses.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->receipt = $this->stock->post('IN', [
        $this->stock->line('BEANS', 50),
        $this->stock->line('PHONE', 2, serials: ['SN-AB1', 'SN-AB2']),
        $this->stock->line('YOGURT', 12, expiry: '2027-03-12', batch: 'B-1042'),
    ]);
});

/**
 * @return array<string, list<string>> group key => result titles
 */
function found(string $text): array
{
    return collect(Search::run($text))->mapWithKeys(fn (array $group) => [$group['key'] => array_column($group['rows'], 'title')])->all();
}

/**
 * @return list<string> result urls of one group
 */
function urls(string $text, string $group): array
{
    return array_column(collect(Search::run($text))->firstWhere('key', $group)['rows'] ?? [], 'url');
}

it('finds nothing for less than two characters', function () {
    $this->actingAs($this->stock->user);

    expect(Search::run(''))->toBe([])->and(Search::run(' S '))->toBe([]);
});

it('finds a document by its full or partial number and opens its details page', function () {
    $this->actingAs($this->stock->user);
    $number = $this->receipt->doc_no;

    expect(found($number)['documents'])->toBe([$number])
        ->and(urls($number, 'documents'))->toBe([route('receive.show', $number)])
        ->and(found(strtolower($number))['documents'])->toBe([$number])
        ->and(found(substr($number, -6))['documents'])->toContain($number);
});

it('puts the exact number first', function () {
    $this->actingAs($this->stock->user);
    $note = $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]);

    expect(found($note->doc_no)['documents'][0])->toBe($note->doc_no);
});

it('finds orders and documents by the customer’s own reference', function () {
    $this->actingAs($this->stock->user);
    $order = $this->stock->order([$this->stock->line('BEANS', 2)]);
    DB::table('stock_draft')->where('id', $order->id)->update(['reference_note' => 'PO-77812']);
    DB::table('stock_transaction')->where('id', $this->receipt->id)->update(['reference_note' => 'Supplier inv PO-77812']);

    expect(found('po-778')['documents'])->toEqualCanonicalizing([$order->doc_no, $this->receipt->doc_no])
        ->and(urls($order->doc_no, 'documents'))->toBe([route('orders.show', $order->doc_no)]);
});

it('shows only the document types the user may open', function () {
    $this->actingAs(userWithPermissions($this->stock->warehouse, ['stock.out.view']));

    expect(found($this->receipt->doc_no))->not->toHaveKey('documents');
});

it('shows only documents and stock of the user’s warehouses', function () {
    $this->actingAs(userWithRole('ADMIN', Warehouse::factory()->create()));

    expect(found($this->receipt->doc_no))->not->toHaveKey('documents')
        ->and(found('SN-AB'))->not->toHaveKey('serials')
        ->and(found('B-10'))->not->toHaveKey('batches');
});

it('finds items by code, name or barcode, never services, and opens the Stock page filtered to the item', function () {
    $this->actingAs($this->stock->user);
    DB::table('product_barcode')->insert(['product_id' => $this->stock->id('BEANS'), 'barcode' => '6291041500213']);
    $hour = Unit::firstOrCreate(['code' => 'HR'], ['name' => 'Hour']);
    Product::create(['customer_id' => $this->stock->customer->id, 'sku' => 'BEANS-PACK', 'name_en' => 'Beans packing', 'base_unit_id' => $hour->id, 'is_service' => true]);

    expect(found('beans')['items'])->toBe(['Canned Beans'])
        ->and(found('yogu')['items'])->toBe(['Yogurt'])
        ->and(found('6291041500213')['items'])->toBe(['Canned Beans']);

    $this->get(urls('beans', 'items')[0])->assertRedirect(route('stock'));
    expect(ListState::get('stock')['properties']['filters'])->toMatchArray([
        'customer' => $this->stock->customer->id, 'search' => 'BEANS', 'warehouse' => 'all',
    ]);
});

it('finds customers and opens the Stock page filtered to them', function () {
    $this->actingAs($this->stock->user);

    expect(found('acme')['customers'])->toBe(['Acme Foods']);

    $this->get(urls('acme', 'customers')[0])->assertRedirect(route('stock'));
    expect(ListState::get('stock')['properties']['filters']['customer'])->toBe($this->stock->customer->id);
});

it('finds serial numbers and batches in stock and opens the item’s stock', function () {
    $this->actingAs($this->stock->user);
    $phone = route('stock.item', [$this->stock->id('PHONE'), $this->stock->warehouse->id]);
    $yogurt = route('stock.item', [$this->stock->id('YOGURT'), $this->stock->warehouse->id]);

    expect(found('sn-ab'))->toMatchArray(['serials' => ['SN-AB1', 'SN-AB2']])
        ->and(urls('SN-AB1', 'serials'))->toBe([$phone])
        ->and(urls('b-10', 'batches'))->toBe([$yogurt]);

    $this->get($phone)->assertOk();
});

it('needs stock.view for items, customers, serials and batches', function () {
    $this->actingAs(userWithPermissions($this->stock->warehouse, ['stock.in.view']));

    expect(array_keys(found('acme')))->toBe([])
        ->and(array_keys(found('SN-AB')))->toBe([])
        ->and(array_keys(found('beans')))->toBe([]);
});

it('limits a customer portal user to their own customer', function () {
    $other = Customer::factory()->create(['code' => 'ACMX', 'name' => 'Acme Mixed']);
    $this->actingAs($this->stock->user);
    expect(found('acm')['customers'])->toEqualCanonicalizing(['Acme Foods', 'Acme Mixed']);

    $portal = userWithRole('CUSTOMER', $this->stock->warehouse);
    $portal->forceFill(['customer_id' => $other->id])->save();
    $this->actingAs($portal->refresh());

    expect(found('acm')['customers'] ?? [])->not->toContain('Acme Foods')
        ->and(found('beans'))->not->toHaveKey('items');
});

it('renders grouped results as links in the top bar', function () {
    Livewire::actingAs($this->stock->user)
        ->test(GlobalSearch::class)
        ->assertSee(__('search.placeholder'))
        ->set('search', 'beans')
        ->assertSee(__('search.groups.items'))
        ->assertSee('Canned Beans')
        ->set('search', 'zzzz-nothing')
        ->assertSee(__('search.no_results', ['text' => 'zzzz-nothing']));
});

it('is in the top bar of every page', function () {
    $this->actingAs($this->stock->user)->get(route('stock'))->assertSeeLivewire(GlobalSearch::class);
});
