<?php

use App\Livewire\Transactions\TransactionList;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

dataset('types', fn () => [
    'Receive' => ['IN', 'receive'],
    'Delivery note' => ['OUT', 'delivery-note'],
    'Transfer' => ['TRANSFER', 'transfer'],
    'Adjustment' => ['ADJUSTMENT', 'adjustment'],
    'Damage' => ['DAMAGE', 'damage'],
    'Opening Balance' => ['OPENING_BALANCE', 'opening-balance'],
]);

/**
 * A document of the type to look at, posted for real (no approval limits, so
 * Damage and Adjustment are completed at once).
 *
 * @return array{0: User, 1: array{reference: string, status: string, item: string}}
 */
function sampleDocument(string $code): array
{
    $type = TransactionTypes::get($code);
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 3, 'BOX')]);

    if ($type->dispatchReceive()) {
        $transfer = $stock->dispatch([$stock->line('BEANS', 5)], Warehouse::factory()->create(['name' => 'Tripoli']));

        return [$stock->user, ['reference' => $transfer->doc_no, 'status' => 'DISPATCHED', 'item' => 'Canned Beans']];
    }

    $reasons = $type->reasons();
    $reference = $code === $receipt->transaction_type
        ? $receipt->doc_no
        // A count of 5 against the 36 in stock; 5 for everything else.
        : $stock->post($code, [$stock->line('BEANS', 5)], reason: $reasons[0] ?? null)->doc_no;

    return [$stock->user, ['reference' => $reference, 'status' => 'POSTED', 'item' => 'Canned Beans']];
}

it('renders the list, new and details pages of every type in its own colour', function (string $code, string $slug) {
    $type = TransactionTypes::get($code);
    [$user, $first] = sampleDocument($code);

    expect($type->slug())->toBe($slug);

    $this->actingAs($user)->get(route($slug))
        ->assertOk()
        ->assertSee($type->label('title'))
        ->assertSee($type->colours()['icon'], false)
        ->assertSee($first['reference'])
        ->assertSee(route($slug.'.show', $first['reference']), false)
        ->assertSee($type->label('new'));

    $this->actingAs($user)->get(route($slug.'.create'))
        ->assertOk()
        ->assertSee($type->label('new'))
        ->assertSee($type->colours()['strong'], false)
        ->assertSee($type->label('save'));

    $this->actingAs($user)->get(route($slug.'.show', $first['reference']))
        ->assertOk()
        ->assertSee($first['reference'])
        ->assertSee($type->colours()['icon'], false)
        ->assertSee(Statuses::label($first['status']))
        ->assertSee($first['item'])
        ->assertSee(__('transactions.activity.heading'));
})->with('types');

it('renders every type in Arabic, right to left, with references kept left to right', function (string $code, string $slug) {
    [$user, $document] = sampleDocument($code);
    $user->update(['preferred_lang' => 'ar']);
    $reference = $document['reference'];

    foreach ([route($slug), route($slug.'.create'), route($slug.'.show', $reference)] as $url) {
        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('transactions.types.'.config("transactions.types.$code.lang").'.title', [], 'ar'));
    }

    expect($this->actingAs($user)->get(route($slug.'.show', $reference))->getContent())
        ->toMatch('/<h1\s[^>]*dir="ltr"[^>]*>\s*'.preg_quote($reference, '/').'\s*<\/h1>/');
})->with('types');

it('shows transfer-specific columns: From → To on the list, Sent | Received on details', function () {
    [$user, $document] = sampleDocument('TRANSFER');

    $this->actingAs($user)->get(route('transfer'))
        ->assertSee(__('transactions.list.route'))
        ->assertSee('Tripoli');
    $this->actingAs($user)->get(route('transfer.show', $document['reference']))
        ->assertSee(__('transactions.lines.sent'))
        ->assertSee(__('transactions.lines.received'));
});

it('shows In system | Counted | Difference on adjustment details', function () {
    [$user, $document] = sampleDocument('ADJUSTMENT');

    $this->actingAs($user)
        ->get(route('adjustment.show', $document['reference']))
        ->assertSee(__('transactions.lines.in_system'))
        ->assertSee(__('transactions.lines.counted'))
        ->assertSee(__('transactions.lines.difference'));
});

it('maps statuses in one place and never shows Draft', function () {
    expect(Statuses::label('POSTED'))->toBe('Completed')
        ->and(Statuses::label('DISPATCHED'))->toBe('In transit')
        ->and(Statuses::label('PENDING_APPROVAL'))->toBe('Pending approval')
        ->and(Statuses::label('REJECTED'))->toBe('Rejected')
        ->and(Statuses::label('CANCELLED'))->toBe('Cancelled')
        ->and(Statuses::options())->not->toHaveKey('DRAFT')
        ->and(array_keys(Statuses::options()))->toBe(['PENDING_APPROVAL', 'DISPATCHED', 'POSTED', 'REJECTED', 'CANCELLED']);
});

it('shows the reason on the adjustment (instead of the total) and damage lists', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 3, 'BOX')]);
    $stock->post('ADJUSTMENT', [$stock->line('BEANS', 30)], reason: 'LOSS_THEFT');

    $adjustments = Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'ADJUSTMENT'])->call('loadRows');
    expect($adjustments->viewData('rows')->first())->toMatchArray(['reason' => 'LOSS_THEFT', 'total' => null]);
    $adjustments->assertSee(__('transactions.list.reason'))
        ->assertSee(__('transactions.reasons.LOSS_THEFT'))
        ->assertDontSee(__('transactions.list.total'));

    // Damage shows both its total quantity and its reason.
    $stock->post('DAMAGE', [$stock->line('BEANS', 2)], reason: 'TRANSPORT');
    $damages = Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'DAMAGE'])->call('loadRows')
        ->assertSee(__('transactions.list.total'))
        ->assertSee(__('transactions.list.reason'))
        ->assertSee(__('transactions.reasons.TRANSPORT'));
    expect($damages->viewData('rows')->first())->toMatchArray(['total' => 2.0, 'reason' => 'TRANSPORT']);

    // Other lists keep their total quantity and get no reason.
    $receipts = Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'IN'])->call('loadRows')
        ->assertSee(__('transactions.list.total'))
        ->assertDontSee(__('transactions.list.reason'));
    expect($receipts->viewData('rows')->first())->toMatchArray(['total' => 36.0, 'reason' => null]);
});

it('offers only the statuses a type can reach, from its transaction_type flags', function () {
    expect(array_keys(Statuses::options(TransactionTypes::get('OPENING_BALANCE'))))->toBe(['POSTED', 'CANCELLED'])
        ->and(array_keys(Statuses::options(TransactionTypes::get('IN'))))->toBe(['POSTED', 'CANCELLED'])
        ->and(array_keys(Statuses::options(TransactionTypes::get('TRANSFER'))))->toBe(['DISPATCHED', 'POSTED', 'CANCELLED'])
        ->and(array_keys(Statuses::options(TransactionTypes::get('DAMAGE'))))->toBe(['PENDING_APPROVAL', 'POSTED', 'REJECTED', 'CANCELLED']);
});

it('drops a remembered status the opening balance list does not offer', function () {
    $stock = Stock::create();
    session([
        'list_state.opening-balance' => ['properties' => ['status' => 'DISPATCHED'], 'page' => 1],
    ]);

    $list = Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'OPENING_BALANCE']);

    expect($list->get('status'))->toBe('')
        ->and($list->viewData('statuses'))->not->toHaveKeys(['DISPATCHED', 'REJECTED', 'PENDING_APPROVAL']);
    $list->assertDontSee(__('transactions.status.in_transit'))->assertDontSee(__('transactions.status.rejected'));
});

it('reads behaviour from the transaction_type table', function () {
    expect(TransactionTypes::get('TRANSFER')->requiresDestination())->toBeTrue()
        ->and(TransactionTypes::get('TRANSFER')->headerFields())->toContain('to_warehouse')
        ->and(TransactionTypes::get('OUT')->requiresDestination())->toBeFalse()
        ->and(TransactionTypes::get('DAMAGE')->supportsApproval())->toBeTrue()
        ->and(TransactionTypes::get('IN')->supportsApproval())->toBeFalse()
        ->and(TransactionTypes::get('IN')->postingDirection())->toBe('IN')
        ->and(TransactionTypes::get('OUT')->postingDirection())->toBe('OUT')
        ->and(collect(TransactionTypes::all())->filter->postsDirectly()->keys()->sort()->values()->all())->toBe(['IN', 'OPENING_BALANCE', 'OUT'])
        ->and(TransactionTypes::get('DAMAGE')->usesExpired())->toBeTrue()
        ->and(TransactionTypes::get('OUT')->usesExpired())->toBeFalse();
});

it('hides the New button without the create permission', function () {
    $operator = userWithRole('OPERATOR');

    $this->actingAs($operator)->get(route('delivery-note'))->assertSee('href="'.route('delivery-note.create').'"', false);
    $this->actingAs($operator)->get(route('opening-balance'))
        ->assertOk()
        ->assertDontSee('href="'.route('opening-balance.create').'"', false);
    $this->actingAs($operator)->get(route('opening-balance.create'))->assertForbidden();
});

it('numbers new delivery notes DN- and still opens and finds those numbered ISS- before the rename', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 3, 'BOX')]);
    $old = $stock->post('OUT', [$stock->line('BEANS', 1)]);
    DB::table('stock_transaction')->where('id', $old->id)->update(['doc_no' => 'ISS-2026-000007']);
    $new = $stock->post('OUT', [$stock->line('BEANS', 2)]);

    expect($new->doc_no)->toStartWith('DN-');

    $this->actingAs($stock->user)->get(route('delivery-note.show', 'ISS-2026-000007'))->assertOk()->assertSee('ISS-2026-000007');

    Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'OUT'])
        ->set('search', 'ISS-2026')
        ->assertSee('ISS-2026-000007')
        ->assertDontSee($new->doc_no);
});

it('returns 404 for an unknown reference', function () {
    $this->actingAs(userWithRole('ADMIN'))->get(route('delivery-note.show', 'DN-2026-999999'))->assertNotFound();
});
