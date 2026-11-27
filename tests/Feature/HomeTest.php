<?php

use App\Livewire\Home;
use App\Livewire\Transactions\TransactionList;
use App\Models\User;
use App\Services\Stock\PostingService;
use App\Support\ListState;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ReportData;

/*
 * Home in two versions: staff see what waits for them in the chosen warehouse,
 * a customer user sees their own orders and stock only. A card opens its list
 * already filtered, through the session (never the URL).
 */

beforeEach(function () {
    $this->data = ReportData::create();
    $this->stock = $this->data->stock;

    $this->portal = userWithRole('CUSTOMER', $this->data->beirut);
    $this->portal->forceFill(['customer_id' => $this->data->acme->id])->save();
});

function homeCard(Testable $home, string $key): ?array
{
    return collect($home->viewData('cards'))->firstWhere('key', $key);
}

it('starts staff on their default warehouse and counts each warehouse on its own', function () {
    // The admin works in Beirut Main and Tripoli; the transfer in transit goes to Tripoli.
    $home = Livewire::actingAs($this->data->admin)->test(Home::class)
        ->assertSet('warehouse', $this->data->beirut->id)
        ->assertSee('data-home-warehouse', false);

    expect(homeCard($home, 'arriving')['count'])->toBe(0);
    // Nothing waiting: named on the "All caught up on" line, not a card.
    $home->assertDontSee('data-attention="arriving"', false)->assertSee('data-caught-up', false);

    $home->set('warehouse', $this->data->tripoli->id);
    expect(homeCard($home, 'arriving')['count'])->toBe(1);

    $home->set('warehouse', 'all');
    expect(homeCard($home, 'arriving')['count'])->toBe(1);

    // A warehouse that isn't the user's falls back to their default.
    $home->set('warehouse', '999999');
    $home->assertSet('warehouse', $this->data->beirut->id);
});

it('opens a card on its list, filtered as counted, with nothing in the URL', function () {
    $home = Livewire::actingAs($this->data->admin)->test(Home::class)->set('warehouse', $this->data->tripoli->id);

    $home->call('open', 'arriving')->assertRedirect(route('transfer'));

    expect(ListState::get('transfer')['properties'])->toMatchArray([
        'direction' => 'incoming', 'status' => 'DISPATCHED', 'warehouse' => $this->data->tripoli->id,
    ]);

    // The list shows exactly what the card counted.
    Livewire::actingAs($this->data->admin)->test(TransactionList::class, ['type' => 'TRANSFER'])
        ->call('loadRows')
        ->assertSee($this->data->transferInTransit->doc_no)
        ->assertDontSee($this->data->shortTransfer->doc_no);
});

it('shows orders to confirm and delivery notes to dispatch, with the most urgent first in the queue', function () {
    $order = $this->stock->order([$this->stock->line('BEANS', 2), $this->stock->line('BEANS', 1, 'BOX')]);
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 1)]);

    $home = Livewire::actingAs($this->data->beirutManager)->test(Home::class);

    expect(homeCard($home, 'orders')['count'])->toBe(1)
        ->and(homeCard($home, 'to_dispatch')['count'])->toBe(1)
        ->and(array_column($home->viewData('queue'), 'number'))->toContain($order->doc_no, $note->doc_no);

    // Each row says how many lines it has.
    $row = collect($home->viewData('queue'))->firstWhere('number', $order->doc_no);
    expect($row['line'])->toContain(trans_choice('home.lines', 2, ['count' => '2']));

    // Due today: the order comes before the note waiting to be dispatched.
    $numbers = array_column($home->viewData('queue'), 'number');
    expect(array_search($order->doc_no, $numbers, true))->toBeLessThan(array_search($note->doc_no, $numbers, true));

    $home->call('open', 'orders')->assertRedirect(route('orders'));
    expect(ListState::get('orders')['properties']['status'])->toBe('DRAFT');

    $home->call('open', 'to_dispatch')->assertRedirect(route('delivery-note'));
    expect(ListState::get('delivery-note')['properties']['status'])->toBe('CONFIRMED');
});

it('marks a delivery note that has been on the road since yesterday as overdue', function () {
    $note = $this->stock->confirmOrder([$this->stock->line('BEANS', 1)]);
    app(PostingService::class)->dispatchDelivery($note, driver()->id, $this->data->admin);
    $note->forceFill(['dispatched_at' => now()->subDays(2)])->save();

    $card = homeCard(Livewire::actingAs($this->data->beirutManager)->test(Home::class), 'dispatched');

    expect($card['count'])->toBe(1)
        ->and($card['tone'])->toBe('rejected')
        ->and($card['subtitle'])->toContain($note->doc_no);
});

it('gives staff figures for the warehouse, never adding up quantities of different items', function () {
    $glance = collect(Livewire::actingAs($this->data->beirutManager)->test(Home::class)->viewData('glance'))->keyBy('key');

    // Beans, Yogurt and Phone of Acme, Rice of Cedar.
    expect($glance['items']['value'])->toBe('4')
        ->and($glance['items']['note'])->toBe(trans_choice('home.glance.items_note', 2, ['count' => '2']))
        // The Yogurt lot expiring in 10 days.
        ->and($glance['expiring']['value'])->toBe('1')
        ->and($glance['expiring']['alert'])->toBeTrue();
});

it('shows a customer user only their own stock and orders', function () {
    $this->stock->order([$this->stock->line('BEANS', 2)]);

    $home = Livewire::actingAs($this->portal)->test(Home::class)
        ->assertViewIs('livewire.home.customer')
        ->assertSee($this->data->acme->name)
        ->assertDontSee('data-home-warehouse', false);

    // Beans, Yogurt and Phone: Cedar's Rice in the same warehouse is not theirs.
    expect($home->viewData('snapshot')['items'])->toBe(3)
        ->and(homeCard($home, 'expiring')['count'])->toBe(1)
        ->and(homeCard($home, 'editable')['count'])->toBe(1)
        ->and(homeCard($home, 'on_way')['count'])->toBe(0)
        ->and(homeCard($home, 'orders'))->toBeNull()
        ->and(collect($home->viewData('soonest'))->pluck('item')->unique()->all())->toBe(['Yogurt']);

    $home->call('open', 'editable')->assertRedirect(route('orders'));
    expect(ListState::get('orders')['properties']['status'])->toBe('DRAFT');

    // No staff card opens for them.
    Livewire::actingAs($this->portal)->test(Home::class)->call('open', 'to_dispatch')->assertNotFound();
});

it('lets a customer user check their stock and remembers what they looked for', function () {
    $home = Livewire::actingAs($this->portal)->test(Home::class)
        ->set('stockSearch', 'Yogurt')
        ->call('searchStock')
        ->assertRedirect(route('stock'));

    expect(ListState::get('stock')['properties']['filters'])->toMatchArray(['search' => 'Yogurt', 'warehouse' => $this->data->beirut->id]);

    Livewire::actingAs($this->portal)->test(Home::class)
        ->assertViewHas('recentSearches', ['Yogurt'])
        ->assertSee('data-recent-search', false);

    // A user without the Stock page can't search it.
    $auditor = User::factory()->create(['customer_id' => $this->data->acme->id]);
    $auditor->warehouses()->attach($this->data->beirut->id);
    Livewire::actingAs($auditor)->test(Home::class)->call('searchStock', 'Yogurt')->assertForbidden();
});

it('is translated in Arabic, right to left', function () {
    $this->data->beirutManager->update(['preferred_lang' => 'ar']);

    $this->actingAs($this->data->beirutManager)->get(route('home'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('home.attention', [], 'ar'))
        ->assertSee(__('home.queue.title', [], 'ar'));

    $this->portal->update(['preferred_lang' => 'ar']);
    $this->actingAs($this->portal)->get(route('home'))
        ->assertOk()
        ->assertSee(__('home.customer.check.title', [], 'ar'))
        ->assertSee(__('home.customer.snapshot.title', [], 'ar'));
});
