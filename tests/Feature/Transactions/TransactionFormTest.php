<?php

use App\Livewire\Transactions\TransactionForm;
use App\Models\Warehouse;
use App\Services\Stock\Availability;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Catalog;

beforeEach(function () {
    $this->catalog = Catalog::create();
});

/**
 * The form for one type, as an admin in the catalog's warehouse, with Acme Foods chosen.
 */
function form(Catalog $catalog, string $type = 'OUT', bool $chooseAcme = true): Testable
{
    $form = Livewire::actingAs($catalog->user())->test(TransactionForm::class, ['type' => $type]);

    return $chooseAcme ? $form->call('chooseCustomer', $catalog->acme->id) : $form;
}

/**
 * @return list<string> row keys in order
 */
function rowKeys(Testable $form): array
{
    return array_keys($form->get('lines'));
}

/**
 * Whether the element matching $pattern (an opening tag) carries the boolean
 * `disabled` attribute. Class names like "disabled:opacity-50" and
 * wire:loading.attr="disabled" don't count.
 */
function tagDisabled(string $html, string $pattern): bool
{
    preg_match($pattern, $html, $match);
    expect($match)->not->toBeEmpty();

    return (bool) preg_match('/\sdisabled(?=[\s>\/])/', preg_replace('/"[^"]*"/', '""', $match[0]));
}

function saveDisabled(Testable $form): bool
{
    return tagDisabled($form->html(), '/<button\s[^>]*type="submit"[^>]*>/s');
}

it('starts with one empty row and the rest of the form locked until a customer is chosen', function () {
    $form = form($this->catalog, chooseAcme: false);

    expect(rowKeys($form))->toHaveCount(1)
        ->and(tagDisabled($form->html(), '/<input\s[^>]*id="field-date"[^>]*>/s'))->toBeTrue()
        ->and(tagDisabled($form->html(), '/<fieldset\s[^>]*data-lines[^>]*>/s'))->toBeTrue()
        ->and(saveDisabled($form))->toBeTrue()
        ->and($form->html())->toContain('Acme Foods')->toContain('Cedar Trading');

    $form->call('chooseCustomer', $this->catalog->acme->id);

    expect(tagDisabled($form->html(), '/<input\s[^>]*id="field-date"[^>]*>/s'))->toBeFalse()
        ->and(tagDisabled($form->html(), '/<fieldset\s[^>]*data-lines[^>]*>/s'))->toBeFalse();
});

it('shows the chosen customer as selected, and only that customer\'s items count on a row', function (string $type) {
    $form = form($this->catalog, $type, chooseAcme: false)->call('chooseCustomer', $this->catalog->cedar->id);

    preg_match_all('/<option\s[^>]*\sselected(?=[\s>])[^>]*>([^<]*)</', $form->html(), $selected);

    expect($selected[1])->toContain('Cedar Trading')->not->toContain('Acme Foods');

    [$row] = rowKeys($form);

    // Another customer's item gets no unit; the chosen customer's item does.
    $form->set("lines.$row.item", $this->catalog->id('OO-1L'))->assertSet("lines.$row.unit", '');
    $form->set("lines.$row.item", $this->catalog->id('CT-T12'))->assertSet("lines.$row.unit", 'PC');
})->with(['IN', 'OUT', 'DAMAGE', 'ADJUSTMENT', 'OPENING_BALANCE', 'TRANSFER']);

it('only offers active customers', function () {
    $this->catalog->cedar->update(['active' => false]);

    form($this->catalog, chooseAcme: false)
        ->assertDontSee('Cedar Trading')
        ->call('chooseCustomer', $this->catalog->cedar->id)
        ->assertSet('customerId', '');
});

it('pre-fills today and pre-selects the base unit', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('OO-1L'));

    expect($form->get('date'))->toBe(now()->toDateString())
        ->and($form->get("lines.$row.unit"))->toBe('BTL');
});

it('appends an empty row as soon as the last row gets a quantity', function () {
    $form = form($this->catalog);
    [$first] = rowKeys($form);

    $form->set("lines.$first.item", $this->catalog->id('CB-400'));
    expect(rowKeys($form))->toHaveCount(1);

    $form->set("lines.$first.qty", '3');
    expect(rowKeys($form))->toHaveCount(2);

    $second = rowKeys($form)[1];
    expect($form->get("lines.$second"))->toBe(['item' => null, 'unit' => '', 'qty' => '', 'expiry' => '', 'serials' => '', 'picked' => []]);

    // Editing an earlier row does not add more rows.
    $form->set("lines.$first.qty", '4');
    expect(rowKeys($form))->toHaveCount(2);
});

it('ignores the empty last row', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '3');

    expect(rowKeys($form))->toHaveCount(2)
        ->and(saveDisabled($form))->toBeFalse()
        ->and($form->html())->toContain('1 item · 3 Piece');

    $form->call('save')->assertHasNoErrors();

    $issue = DB::table('stock_transaction')->where('transaction_type', 'OUT')->first();
    $form->assertRedirect(route('issue.show', $issue->doc_no));

    expect(DB::table('stock_transaction_line')->where('transaction_id', $issue->id)->count())->toBe(1);
});

it('keeps Save disabled while a row is invalid and explains why', function (string $qty, string $error, array $replace) {
    $form = form($this->catalog);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.unit", 'BOX')->set("lines.$row.qty", $qty);

    expect(saveDisabled($form))->toBeTrue()
        ->and($form->html())->toContain(e(__('transactions.form.errors.'.$error, $replace)));

    $form->call('save')->assertNoRedirect();
})->with([
    'more than available' => ['6', 'not_enough', ['qty' => '5 Box + 4 Piece']],
    'zero' => ['0', 'more_than_zero', []],
    'not a number' => ['abc', 'number', []],
    'a decimal' => ['1.5', 'number', []],
    'over 999,999' => ['1000000', 'too_large', ['max' => '999,999']],
]);

it('counts earlier rows of the same item against what is available', function () {
    $form = form($this->catalog);
    [$first] = rowKeys($form);
    // Same item, another unit (the same unit twice is refused: rule 12): 5 Box = 60 Piece.
    $form->set("lines.$first.item", $this->catalog->id('CB-400'))->set("lines.$first.unit", 'BOX')->set("lines.$first.qty", '5');

    $second = rowKeys($form)[1];
    $form->set("lines.$second.item", $this->catalog->id('CB-400'))->set("lines.$second.qty", '5');

    expect($form->html())->toContain(e(__('transactions.form.errors.not_enough', ['qty' => '4 Piece'])))
        ->and(saveDisabled($form))->toBeTrue();
});

it('shows what is available, broken down, and the base-unit conversion', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.unit", 'BOX')->set("lines.$row.qty", '3');

    expect($form->html())
        ->toContain('= 36 Piece')
        ->toContain('5 Box + 4 Piece')
        ->toContain('1 item · 36 Piece');
});

it('reads what is available from the current warehouse only', function () {
    $elsewhere = Warehouse::factory()->create();
    DB::table('stock_balance')->insert([
        'warehouse_id' => $elsewhere->id, 'customer_id' => $this->catalog->acme->id,
        'product_id' => $this->catalog->id('CB-400'), 'qty_base' => 1000,
    ]);

    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '65');

    expect(saveDisabled($form))->toBeTrue();
});

it('does not limit receipts to what is in stock', function () {
    $form = form($this->catalog, 'IN');
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('RC-5K'))->set("lines.$row.qty", '1000');

    expect($form->html())->toContain(e(__('transactions.form.in_stock_now')))
        ->and(saveDisabled($form))->toBeFalse();
});

it('asks for the expiry date (and no batch number) on receipts of expiry-tracked items only', function () {
    $form = form($this->catalog, 'IN');
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('YG-500'))->set("lines.$row.qty", '10');

    expect($form->html())->toContain("line-$row-expiry")
        ->and($form->html())->toContain(e(__('transactions.form.errors.expiry_required')))
        ->and($form->html())->not->toContain("line-$row-batch")
        ->and(saveDisabled($form))->toBeTrue();

    $form->set("lines.$row.expiry", now()->addMonth()->toDateString());

    expect(saveDisabled($form))->toBeFalse();

    $other = rowKeys($form)[1];
    $form->set("lines.$other.item", $this->catalog->id('RC-5K'));
    expect($form->html())->not->toContain("line-$other-expiry");
});

it('suggests the nearest expiry date on an issue, and checks the quantity against the date chosen', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    [$soon, $late] = [now()->addDays(14)->toDateString(), now()->addDays(40)->toDateString()];

    $form->set("lines.$row.item", $this->catalog->id('YG-500'));

    expect($form->get("lines.$row.expiry"))->toBe($soon)
        ->and($form->html())->toContain("line-$row-expiry")
        ->toContain(e(Format::date(now()->addDays(14))));

    // The nearest date holds 24: 30 is too many there, though the item has 60.
    $form->set("lines.$row.qty", '30');
    expect($form->html())->toContain(e(__('transactions.form.errors.not_enough', ['qty' => '24 Piece'])))
        ->and(saveDisabled($form))->toBeTrue();

    $form->set("lines.$row.expiry", $late);
    expect($form->html())->toContain(e(Format::date(now()->addDays(40))))
        ->and(saveDisabled($form))->toBeFalse();
});

it('offers expired stock on a damage only', function () {
    $gone = now()->subDays(2)->toDateString();
    $this->catalog->expiring($this->catalog->items['YG-500'], $gone, 6);

    foreach (['OUT' => false, 'TRANSFER' => false, 'DAMAGE' => true] as $type => $offered) {
        $form = form($this->catalog, $type);
        [$row] = rowKeys($form);
        $form->set("lines.$row.item", $this->catalog->id('YG-500'));

        expect(str_contains($form->html(), 'value="'.$gone.'"'))->toBe($offered)
            // Nearest expiry first: on a damage that is the expired stock.
            ->and($form->get("lines.$row.expiry"))->toBe($offered ? $gone : now()->addDays(14)->toDateString());
    }
});

it('takes stock from the expiry date chosen in the form', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    [$soon, $late] = [now()->addDays(14)->toDateString(), now()->addDays(40)->toDateString()];

    $form->set("lines.$row.item", $this->catalog->id('YG-500'))
        ->set("lines.$row.expiry", $late)
        ->set("lines.$row.qty", '30')
        ->call('save')
        ->assertHasNoErrors();

    $balances = DB::table('stock_balance')
        ->where('product_id', $this->catalog->id('YG-500'))->pluck('qty_base', 'expiry_date')->map(fn ($q) => (float) $q)->all();

    expect($balances)->toEqualCanonicalizing([$soon => 24.0, $late => 6.0]);
});

it('works out the adjustment difference from the counted quantity', function () {
    $form = form($this->catalog, 'ADJUSTMENT')->set('reason', 'STOCK_COUNT_VARIANCE');
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '70');
    expect($form->html())->toContain('+6');

    $form->set("lines.$row.qty", '60');
    expect($form->html())->toContain('-4')
        ->and(saveDisabled($form))->toBeFalse();
});

it('counts an expiry date of an expiry-tracked item against that date', function () {
    $form = form($this->catalog, 'ADJUSTMENT')->set('reason', 'STOCK_COUNT_VARIANCE');
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('YG-500'))->set("lines.$row.expiry", now()->addDays(40)->toDateString())->set("lines.$row.qty", '30');

    // Both dates are offered; the one counted holds 36, so 30 is 6 short.
    expect($form->html())->toContain('value="'.now()->addDays(14)->toDateString().'"')->toContain('-6')
        ->and(saveDisabled($form))->toBeFalse();
});

it('sums the net difference in the footer of a count', function () {
    $form = form($this->catalog, 'ADJUSTMENT')->set('reason', 'STOCK_COUNT_VARIANCE');
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '60');

    expect($form->html())->toContain('1 item · Net difference -4 Piece');
});

it('uses only the base unit for serial-numbered items', function () {
    $form = Livewire::actingAs($this->catalog->user())
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->call('chooseCustomer', $this->catalog->cedar->id);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('ES-MCH'));

    expect($form->get("lines.$row.unit"))->toBe('PC')
        ->and($form->html())->not->toMatch('/<select\s[^>]*id="line-'.$row.'-unit"/');
});

it('needs a destination warehouse for transfers', function () {
    $destination = Warehouse::factory()->create();
    $form = form($this->catalog, 'TRANSFER');
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '2');

    expect(saveDisabled($form))->toBeTrue();

    $form->set('toWarehouse', $destination->id);
    expect(saveDisabled($form))->toBeFalse();
});

it('asks before clearing the lines when the customer changes', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '3');

    // Asked inline; nothing changes yet.
    $form->call('chooseCustomer', $this->catalog->cedar->id)
        ->assertSet('customerId', $this->catalog->acme->id)
        ->assertSet('pendingCustomer', $this->catalog->cedar->id)
        ->assertSee(trans_choice('transactions.form.change_customer_warning', 1, ['customer' => 'Cedar Trading', 'count' => 1]));
    expect($form->get("lines.$row.item"))->toBe($this->catalog->id('CB-400'));

    // Keeping the customer keeps the lines.
    $form->call('keepCustomer')->assertSet('pendingCustomer', null);
    expect($form->get("lines.$row.item"))->toBe($this->catalog->id('CB-400'));

    // Confirming switches and clears.
    $form->call('chooseCustomer', $this->catalog->cedar->id)->call('confirmCustomerChange')->assertSet('customerId', $this->catalog->cedar->id);
    expect($form->get('lines'))->toHaveCount(1)
        ->and(array_values($form->get('lines'))[0]['item'])->toBeNull();
});

it('changes customer without asking while there are no lines', function () {
    form($this->catalog)->call('chooseCustomer', $this->catalog->cedar->id)
        ->assertSet('customerId', $this->catalog->cedar->id)
        ->assertSet('pendingCustomer', null);
});

it('refuses an item of another customer', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CT-T12'))->set("lines.$row.qty", '1');

    expect($form->html())->toContain(e(__('transactions.form.errors.choose_item')))
        ->and(saveDisabled($form))->toBeTrue();
});

it('deletes a row and keeps an empty row at the end', function () {
    $form = form($this->catalog);
    [$first] = rowKeys($form);
    $form->set("lines.$first.item", $this->catalog->id('CB-400'))->set("lines.$first.qty", '3');

    $form->call('deleteLine', $first);

    expect(rowKeys($form))->toHaveCount(1)
        ->and(array_values($form->get('lines'))[0]['item'])->toBeNull();
});

it('puts Item, Unit and Quantity in Tab order, with delete out of the way', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '3');
    $next = rowKeys($form)[1];
    $html = $form->html();

    $positions = array_map(fn ($id) => strpos($html, 'id="'.$id.'"'), [
        'field-customer', "line-$row-unit", "line-$row-qty", "line-$next-item",
    ]);

    expect($positions)->each->toBeInt()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($html)->toMatch('/<button\s[^>]*tabindex="-1"[^>]*wire:click="deleteLine/s');
});

it('shows the approval notice above the limit and changes the button', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);

    // An operator: someone who can't approve damage themselves.
    $form = Livewire::actingAs($this->catalog->user('OPERATOR'))->test(TransactionForm::class, ['type' => 'DAMAGE'])
        ->call('chooseCustomer', $this->catalog->acme->id)
        ->set('reason', 'HANDLING');
    [$row] = rowKeys($form);

    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '5');
    expect($form->html())->not->toContain(e(__('transactions.form.approval_notice')));

    $form->set("lines.$row.qty", '12');
    expect($form->html())->toContain(e(__('transactions.form.approval_notice')))
        ->and($form->html())->toContain(e(__('transactions.form.send_for_approval')));
});

it('posts an issue through PostingService and opens it', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.unit", 'BOX')->set("lines.$row.qty", '2');

    $form->call('save')->assertHasNoErrors();

    $issue = DB::table('stock_transaction')->first();
    $form->assertRedirect(route('issue.show', $issue->doc_no));

    expect($issue->status)->toBe('POSTED')
        ->and(DB::table('stock_movement')->where('transaction_id', $issue->id)->value('qty_base'))->toEqual('24.0000')
        ->and((float) DB::table('stock_balance')->where('product_id', $this->catalog->id('CB-400'))->value('qty_base'))->toBe(40.0)
        ->and(session('toasts'))->toBe([[
            'type' => 'success',
            'message' => __('feedback.saved', ['type' => __('transactions.types.issue.singular'), 'reference' => $issue->doc_no]),
            'url' => route('issue.show', $issue->doc_no),
            'link' => __('feedback.view'),
        ]]);
});

it('re-checks stock when saving, and saves nothing when it is gone', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '60');

    // Someone else takes stock between filling in the form and saving.
    DB::table('stock_balance')->where('product_id', $this->catalog->id('CB-400'))->update(['qty_base' => 50]);

    $form->call('save')
        ->assertNoRedirect()
        ->assertSee(e(__('transactions.form.errors.not_enough', ['qty' => '50 Piece'])));

    expect(DB::table('stock_transaction')->count())->toBe(0);
});

it('shows a refusal from PostingService under the row it concerns', function () {
    $form = form($this->catalog);
    [$row] = rowKeys($form);
    $form->set("lines.$row.item", $this->catalog->id('CB-400'))->set("lines.$row.qty", '10');

    // The form's own check sees 64; posting (under lock) finds only 4.
    app()->instance(Availability::class, new class extends Availability
    {
        public function lockNoExpiry(string $warehouseId, string $customerId, string $productId): float
        {
            return 4;
        }
    });

    $form->call('save')
        ->assertHasErrors("lines.$row.qty")
        ->assertNoRedirect()
        ->assertSee($refusal = __('stock.errors.not_enough', ['qty' => '4 Piece', 'item' => $this->catalog->items['CB-400']->name(), 'warehouse' => $this->catalog->warehouse->name]))
        ->assertDispatched('toast', toast: ['type' => 'error', 'message' => $refusal, 'url' => null, 'link' => null]);

    expect(DB::table('stock_transaction')->count())->toBe(0)
        ->and(saveDisabled($form))->toBeTrue();

    // Changing the line clears the refusal.
    $form->set("lines.$row.qty", '9');
    expect(saveDisabled($form))->toBeFalse();
});

it('takes serial numbers instead of a quantity for serial-numbered items', function () {
    $receipt = Livewire::actingAs($this->catalog->user())
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->call('chooseCustomer', $this->catalog->cedar->id);
    [$row] = rowKeys($receipt);

    $receipt->set("lines.$row.item", $this->catalog->id('ES-MCH'))
        ->set("lines.$row.serials", "SN-100\nSN-101\n\nSN-101");

    expect($receipt->get("lines.$row.qty"))->toBe('3')
        ->and($receipt->html())->toContain(e(__('stock.errors.serial_twice', ['serial' => 'SN-101'])));

    $receipt->set("lines.$row.serials", "SN-100\nSN-101")->call('save')->assertHasNoErrors();

    $issue = Livewire::actingAs($this->catalog->user())
        ->test(TransactionForm::class, ['type' => 'OUT'])
        ->call('chooseCustomer', $this->catalog->cedar->id);
    [$row] = rowKeys($issue);
    $issue->set("lines.$row.item", $this->catalog->id('ES-MCH'));

    expect($issue->html())->toContain('SN-100')->toContain('SN-101');

    $issue->set("lines.$row.picked", ['SN-101'])->call('save')->assertHasNoErrors();

    expect(DB::table('serial_number')->where('serial_no', 'SN-101')->value('status'))->toBe('ISSUED')
        ->and(DB::table('serial_number')->where('serial_no', 'SN-100')->value('status'))->toBe('IN_STOCK');
});

it('needs the type’s create permission', function () {
    Livewire::actingAs(userWithRole('OPERATOR'))
        ->test(TransactionForm::class, ['type' => 'OPENING_BALANCE'])
        ->assertForbidden();
});

it('warns about an expiry date that has passed on a receipt or opening balance, without blocking it', function (string $type) {
    $form = form($this->catalog, $type);
    [$row] = rowKeys($form);
    $warning = e(__('transactions.form.expiry_past'));

    $form->set("lines.$row.item", $this->catalog->id('YG-500'))->set("lines.$row.qty", '10')
        ->set("lines.$row.expiry", now()->addMonth()->toDateString());

    expect($form->html())->not->toContain($warning);

    $form->set("lines.$row.expiry", now()->subDay()->toDateString());

    expect($form->html())->toContain($warning)
        ->and(saveDisabled($form))->toBeFalse();
})->with(['IN', 'OPENING_BALANCE']);
