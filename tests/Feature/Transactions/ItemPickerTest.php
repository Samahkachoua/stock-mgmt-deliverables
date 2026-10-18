<?php

use App\Livewire\ItemPicker;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Catalog;

beforeEach(function () {
    $this->catalog = Catalog::create();
});

function picker(Catalog $catalog, ?string $customerId = null, string $role = 'ADMIN'): Testable
{
    return Livewire::actingAs($catalog->user($role))
        ->test(ItemPicker::class, ['customer' => $customerId ?? $catalog->acme->id, 'inputId' => 'pick']);
}

/**
 * @return list<string> codes of the offered items
 */
function offered(Testable $picker): array
{
    return collect($picker->instance()->results)->pluck('code')->all();
}

it('shows at most 20 matches with name, code and stock', function () {
    $picker = picker($this->catalog)->set('search', '');

    expect(offered($picker))->toHaveCount(ItemPicker::MAX_RESULTS);
    $picker->assertSee('Canned Beans 400g')->assertSee('CB-400')->assertSee('5 Box + 4 Piece');
});

it('searches by code or by name in any language, case-insensitively', function (string $search, string $expected) {
    expect(offered(picker($this->catalog)->set('search', $search)))->toBe([$expected]);
})->with([
    'English name' => ['olive oil', 'OO-1L'],
    'code' => ['cb-4', 'CB-400'],
    'Arabic name' => ['زيت', 'OO-1L'],
    'French name' => ['haricots', 'CB-400'],
]);

it('only offers the chosen customer’s items', function () {
    expect(offered(picker($this->catalog, $this->catalog->cedar->id)->set('search', '')))->toEqualCanonicalizing(['CT-T12', 'ES-MCH'])
        ->and(offered(picker($this->catalog)->set('search', 'cotton')))->toBe([]);
});

it('does not offer inactive items', function () {
    expect(offered(picker($this->catalog)->set('search', 'discontinued')))->toBe([]);
});

it('is disabled until a customer is chosen', function () {
    Livewire::actingAs($this->catalog->user())
        ->test(ItemPicker::class, ['customer' => '', 'inputId' => 'pick'])
        ->assertSee(__('transactions.picker.choose_customer_first'));
});

it('sets the product id when an item is chosen, and ignores other customers’ items', function () {
    picker($this->catalog)
        ->call('choose', $this->catalog->id('CT-T12'))
        ->assertSet('value', null)
        ->call('choose', 'not-a-uuid')
        ->assertSet('value', null)
        ->call('choose', $this->catalog->id('RC-5K'))
        ->assertSet('value', $this->catalog->id('RC-5K'))
        ->assertSet('open', false)
        ->assertSee('Rice 5kg');
});

it('limits a customer portal user to their own customer’s items', function () {
    $user = $this->catalog->user('CUSTOMER');
    $user->forceFill(['is_customer_user' => true, 'customer_id' => $this->catalog->acme->id])->save();

    $picker = Livewire::actingAs($user->fresh())
        ->test(ItemPicker::class, ['customer' => $this->catalog->cedar->id, 'inputId' => 'pick'])
        ->set('search', '');

    expect(offered($picker))->toBe([]);
});
