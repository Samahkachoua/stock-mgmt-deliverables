<?php

use App\Livewire\ItemPicker;
use App\Livewire\Items\ItemForm;
use App\Livewire\Items\ItemList;
use App\Models\Product;
use App\Models\Unit;
use App\Support\StorageStatuses;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Catalog;

beforeEach(function () {
    $this->catalog = Catalog::create();
    $this->unit = fn (string $code) => Unit::where('code', $code)->value('id');
});

/**
 * A new-item form with the required item fields filled in.
 */
function newItem(Catalog $catalog, string $code = 'CP-400'): Testable
{
    return Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(ItemForm::class)
        ->set('customerId', $catalog->acme->id)
        ->set('sku', $code)
        ->set('nameEn', 'Chickpeas 400g');
}

/**
 * @return list<string> keys of the unit rows, in order
 */
function unitRows(Testable $form): array
{
    return array_keys($form->get('units'));
}

/**
 * Records a stock movement for the item, as posting would (test fixture).
 */
function moveStock(Catalog $catalog, Product $product): void
{
    $transaction = DB::table('stock_transaction')->insertGetId([
        'doc_no' => 'RCV-TEST-1', 'transaction_type' => 'IN', 'status' => 'POSTED',
        'warehouse_id' => $catalog->warehouse->id, 'customer_id' => $product->customer_id,
        'created_by' => userWithRole('ADMIN')->id,
    ], 'id');
    $line = DB::table('stock_transaction_line')->insertGetId([
        'transaction_id' => $transaction, 'line_no' => 1, 'product_id' => $product->id,
        'unit_id' => $product->base_unit_id, 'qty_entered' => 1, 'qty_base' => 1,
    ], 'id');
    DB::table('stock_movement')->insert([
        'transaction_id' => $transaction, 'line_id' => $line, 'warehouse_id' => $catalog->warehouse->id,
        'customer_id' => $product->customer_id, 'product_id' => $product->id, 'direction' => 'IN', 'qty_base' => 1,
    ]);
}

// List

it('lists items with their units and tracking, and searches and filters them', function () {
    $list = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(ItemList::class)->set('search', 'cb-400');

    $list->assertSee('Canned Beans 400g')
        ->assertSee('1 Box = 12 Piece')
        ->assertDontSee('Olive Oil 1L');

    $list->set('search', '')->set('customer', $this->catalog->cedar->id)
        ->assertSee('Espresso Machine')
        ->assertSee(__('items.list.serial'))
        ->assertDontSee('Canned Beans 400g');
});

it('filters items by status, keeping inactive ones out of the active list', function () {
    // OLD-1 (Discontinued Item) is inactive in the catalog; CB-400 is active.
    $list = Livewire::actingAs(userWithRole('DATA_ENTRY'))->test(ItemList::class)->call('loadRows')
        ->set('customer', $this->catalog->acme->id)
        ->assertSee('Canned Beans 400g');

    $list->set('search', 'old-1')->assertSee('Discontinued Item');

    $list->set('status', 'active')
        ->assertDontSee('Discontinued Item')
        ->set('search', 'cb-400')
        ->assertSee('Canned Beans 400g');

    $list->set('status', 'inactive')
        ->assertDontSee('Canned Beans 400g')
        ->set('search', '')
        ->assertSee('Discontinued Item')
        ->assertDontSee('Olive Oil 1L');

    $list->call('resetFilters')->assertSet('status', '')->assertSee('Canned Beans 400g');
});

it('shows item names in the user’s language', function () {
    $user = userWithRole('DATA_ENTRY');
    $user->update(['preferred_lang' => 'ar']);

    // The search the user typed earlier, remembered for this list.
    $this->actingAs($user)->withSession(['list_state.items' => ['properties' => ['search' => 'oo-1l'], 'page' => 1]])
        ->get(route('items'))
        ->assertOk()
        ->assertSee('زيت زيتون');
});

it('is only for users who manage items', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('items'))->assertStatus($status);
    $this->actingAs(userWithRole($role))->get(route('items.create'))->assertStatus($status);
    $this->actingAs(userWithRole($role))->get(route('items.edit', $this->catalog->id('CB-400')))->assertStatus($status);
})->with([
    ['DATA_ENTRY', 200],
    ['MANAGER', 200],
    ['OPERATOR', 403],
    ['AUDITOR', 403],
]);

// Creating and editing

it('creates an item with its units, keeping the base unit’s own row at factor 1', function () {
    $form = newItem($this->catalog)->set('nameAr', 'حمص')->set('baseUnitId', ($this->unit)('PC'));
    [$row] = unitRows($form);
    $form->set("units.$row.unit_id", ($this->unit)('BOX'))->set("units.$row.factor", '12');
    $next = unitRows($form)[1];
    $form->set("units.$next.unit_id", ($this->unit)('PLT'))->set("units.$next.factor", '480');

    $form->assertSee(__('items.units.preview', ['qty' => '493 Piece', 'breakdown' => '1 Pallet + 1 Box + 1 Piece']))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('items'));

    $product = Product::where('sku', 'CP-400')->firstOrFail();

    expect($product->units()->with('unit')->get()->mapWithKeys(fn ($row) => [$row->unit->code => [$row->factor_to_base, $row->is_default]])->all())
        ->toEqualCanonicalizing(['PC' => [1.0, true], 'BOX' => [12.0, false], 'PLT' => [480.0, false]])
        ->and($product->name('ar'))->toBe('حمص')
        ->and(DB::table('audit_log')->where('entity', 'product')->where('entity_id', $product->id)->value('action'))->toBe('CREATE');
});

it('appends a new unit row when the last one is filled, and ignores the empty one', function () {
    $form = newItem($this->catalog);
    expect(unitRows($form))->toHaveCount(1);

    [$row] = unitRows($form);
    $form->set("units.$row.unit_id", ($this->unit)('BOX'));
    expect(unitRows($form))->toHaveCount(2);

    $form->set("units.$row.factor", '6')->call('save')->assertHasNoErrors();

    expect(Product::where('sku', 'CP-400')->firstOrFail()->units)->toHaveCount(2);
});

it('applies the same unit rules as the database', function (string $unit, string $factor, string $field, string $error) {
    $form = newItem($this->catalog)->set('baseUnitId', ($this->unit)('PC'));
    [$row] = unitRows($form);

    $form->set("units.$row.unit_id", $unit === '' ? '' : ($this->unit)($unit))
        ->set("units.$row.factor", $factor)
        ->call('save')
        ->assertHasErrors("units.$row.$field")
        ->assertSee(__('items.errors.'.$error));

    expect(Product::where('sku', 'CP-400')->exists())->toBeFalse();
})->with([
    'factor of 1' => ['BOX', '1', 'factor', 'factor_more_than_one'],
    'factor below 1' => ['BOX', '0.5', 'factor', 'factor_more_than_one'],
    'not a number' => ['BOX', 'twelve', 'factor', 'factor_number'],
    'too many decimals' => ['BOX', '1.1234567', 'factor', 'factor_number'],
    'factor missing' => ['BOX', '', 'factor', 'factor_required'],
    'unit missing' => ['', '12', 'unit_id', 'unit_required'],
    'the base unit again' => ['PC', '12', 'unit_id', 'unit_is_base'],
]);

it('refuses the same unit twice', function () {
    $form = newItem($this->catalog);
    [$first] = unitRows($form);
    $form->set("units.$first.unit_id", ($this->unit)('BOX'))->set("units.$first.factor", '12');
    $second = unitRows($form)[1];
    $form->set("units.$second.unit_id", ($this->unit)('BOX'))->set("units.$second.factor", '24');

    $form->call('save')->assertHasErrors("units.$second.unit_id")->assertSee(__('items.errors.unit_twice'));
});

it('keeps codes unique per customer, but not across customers', function () {
    newItem($this->catalog, 'cb-400')->call('save')->assertHasErrors('sku')->assertSee(__('items.errors.code_taken'));

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(ItemForm::class)
        ->set('customerId', $this->catalog->cedar->id)
        ->set('sku', 'CB-400')
        ->set('nameEn', 'Cedar beans')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('sku', 'CB-400')->count())->toBe(2);
});

it('drops the other units of a serial-numbered item', function () {
    $form = newItem($this->catalog)->set('baseUnitId', ($this->unit)('PC'));
    [$row] = unitRows($form);
    $form->set("units.$row.unit_id", ($this->unit)('BOX'))->set("units.$row.factor", '12');

    $form->set('trackSerial', true)
        ->assertSee(__('items.units.serial_note'))
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::where('sku', 'CP-400')->firstOrFail();
    expect($product->track_serial)->toBeTrue()
        ->and($product->units()->pluck('unit_id')->all())->toBe([($this->unit)('PC')]);
});

it('loads an item for editing and records what changed', function () {
    $product = $this->catalog->items['CB-400'];

    $form = Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(ItemForm::class, ['product' => $product])
        ->assertSet('sku', 'CB-400')
        ->assertSet('baseUnitId', ($this->unit)('PC'))
        ->assertSet('locked', false);

    $box = collect($form->get('units'))->firstWhere('unit_id', ($this->unit)('BOX'));
    expect($box['factor'])->toBe('12');

    $form->set('nameEn', 'Canned Beans 410g')->set('threshold', '24')->call('save')->assertHasNoErrors();

    $audit = DB::table('audit_log')->where('entity_id', $product->id)->first();
    expect($product->fresh()->name_en)->toBe('Canned Beans 410g')
        ->and(json_decode($audit->before_data, true))->toMatchArray(['name_en' => 'Canned Beans 400g', 'units' => ['BOX' => 12, 'PC' => 1]])
        ->and(json_decode($audit->after_data, true))->toMatchArray(['name_en' => 'Canned Beans 410g', 'approval_threshold_qty' => '24.0000']);
});

// Locked after stock movements

it('locks customer, base unit and tracking once stock has moved, and says why', function () {
    $product = $this->catalog->items['CB-400'];
    moveStock($this->catalog, $product);

    $form = Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(ItemForm::class, ['product' => $product])
        ->assertSet('locked', true)
        ->assertSee(__('items.locked_notice'));

    foreach (['field-customerId', 'field-baseUnitId', 'field-trackExpiry', 'field-trackSerial'] as $id) {
        expect($form->html())->toMatch('/<(select|input)\s[^>]*id="'.$id.'"[^>]*\sdisabled/s');
    }

    // Even if the browser sends a change anyway, it is refused.
    $form->set('baseUnitId', ($this->unit)('BOX'))->set('trackExpiry', true)->call('save')
        ->assertHasErrors('baseUnitId');

    expect($product->fresh())->base_unit_id->toBe(($this->unit)('PC'))->track_expiry->toBeFalse();

    // Everything else can still change.
    $form->set('nameFr', 'Haricots 400 g')->call('save')->assertHasNoErrors();
    expect($product->fresh()->name_fr)->toBe('Haricots 400 g');
});

it('does not let users without the permission save', function () {
    Livewire::actingAs(userWithRole('OPERATOR'))
        ->test(ItemForm::class, ['product' => $this->catalog->items['CB-400']])
        ->assertForbidden();
});

// Storage status

it('saves an optional storage status, shown with its temperature range', function () {
    newItem($this->catalog)->set('storageStatus', 'FROZEN')->call('save')->assertHasNoErrors();
    newItem($this->catalog, 'CP-500')->call('save')->assertHasNoErrors();

    $frozen = Product::where('sku', 'CP-400')->sole();
    expect($frozen->storage_status)->toBe('FROZEN')
        ->and(Product::where('sku', 'CP-500')->value('storage_status'))->toBeNull()
        ->and(StorageStatuses::label('FROZEN', plain: true))->toBe('Frozen (-25 to -18 °C)')
        ->and(StorageStatuses::label(null))->toBeNull()
        ->and(json_decode(DB::table('audit_log')->where('entity_id', $frozen->id)->value('after_data'), true))->toMatchArray(['storage_status' => 'FROZEN']);

    Livewire::actingAs(userWithRole('DATA_ENTRY'))
        ->test(ItemForm::class, ['product' => $frozen])
        ->assertSet('storageStatus', 'FROZEN')
        ->set('storageStatus', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($frozen->fresh()->storage_status)->toBeNull();
});

it('refuses a storage status that is not in the list', function () {
    newItem($this->catalog)->set('storageStatus', 'WARM')->call('save')->assertHasErrors(['storageStatus']);

    expect(Product::where('sku', 'CP-400')->exists())->toBeFalse();
});

it('shows the storage status in the item picker', function () {
    $product = $this->catalog->items['CB-400'];
    $product->update(['storage_status' => 'CHILLED']);

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(ItemPicker::class, ['customer' => $product->customer_id])
        ->set('search', 'CB-400')
        ->assertSee(StorageStatuses::label('CHILLED'));
});
