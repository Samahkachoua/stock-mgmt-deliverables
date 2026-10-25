<?php

use App\Imports\ImportEngine;
use App\Imports\ImportRefused;
use App\Imports\Imports;
use App\Livewire\Imports\ImportJobPage;
use App\Livewire\Imports\ImportUpload;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\StockTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Format;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Spreadsheets;
use Tests\Support\Stock;

beforeEach(function () {
    Storage::fake(config('imports.disk'));
    $this->stock = Stock::create(); // BEY / ACME: BEANS (Box 12, Pallet 480), YOGURT (expiry), PHONE (serial)
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->saida = Warehouse::factory()->create(['code' => 'SAI', 'name' => 'Saida']);
    $this->manager = userWithRole('MANAGER', $this->stock->warehouse, $this->tripoli);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

/**
 * An opening-balance row in template order: warehouse, customer, item, expiry, quantity, unit, serial.
 *
 * @return list<mixed>
 */
function balanceRow(string $warehouse, string $item, mixed $quantity, string $unit = '', mixed $expiry = '', string $serial = ''): array
{
    return [$warehouse, 'ACME', $item, $expiry, $quantity, $unit, $serial];
}

function balanceFile(array $rows): UploadedFile
{
    return Spreadsheets::upload(
        array_map(fn ($column) => $column->header('en'), Imports::get('opening-balance')->columns()),
        $rows,
        'opening.xlsx',
    );
}

function uploadBalances(User $user, UploadedFile $file): ImportJob
{
    Livewire::actingAs($user)->test(ImportUpload::class, ['type' => 'opening-balance'])
        ->set('file', $file)
        ->call('send')
        ->assertHasNoErrors();

    return ImportJob::query()->where('job_type', 'OPENING_BALANCE')->latest('created_at')->latest('id')->firstOrFail();
}

it('creates one Opening Balance per warehouse and customer, with the right balances', function () {
    $expiry = now()->addMonths(6)->toDateString();
    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'BEANS', 2, 'BOX'),
        balanceRow('bey', 'beans', 5),
        balanceRow('BEY', 'YOGURT', 1, 'BOX', ['date' => $expiry]), // a real Excel date cell
        balanceRow('TRP', 'BEANS', 1, 'PLT'),
    ]));

    expect(app(ImportEngine::class)->counts($job))->toBe(['ready' => 4, 'warnings' => 0, 'errors' => 0, 'imported' => 0])
        ->and(StockTransaction::query()->where('transaction_type', 'OPENING_BALANCE')->count())->toBe(0);

    Livewire::actingAs($this->manager)->test(ImportJobPage::class, ['job' => $job->id])->call('import')->assertSet('problem', null);

    $documents = StockTransaction::query()->where('transaction_type', 'OPENING_BALANCE')->get();
    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('warehouse_id')->sort()->values()->all())->toBe(collect([$this->stock->warehouse->id, $this->tripoli->id])->sort()->values()->all())
        ->and($documents->pluck('status')->unique()->all())->toBe(['POSTED'])
        ->and($this->stock->balance('BEANS'))->toBe(29.0)
        ->and($this->stock->balance('YOGURT', $expiry))->toBe(6.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(480.0)
        ->and($job->fresh()->result['documents'])->toEqualCanonicalizing($documents->pluck('doc_no')->all())
        ->and($documents->first()->getAttribute('reference_note'))->toBe(__('imports.document_note', ['file' => 'opening.xlsx']));
});

it('catches every kind of mistake in an opening balance', function (array $row, string $expected) {
    $job = uploadBalances($this->manager, balanceFile([$row]));
    $result = $job->rows()->sole();

    expect($result->status)->toBe('ERROR')
        ->and($result->text())->toContain($expected);
})->with([
    'warehouse not assigned' => [['SAI', 'ACME', 'BEANS', '', 5, ''], 'You are not assigned to warehouse SAI.'],
    'unknown warehouse' => [['BEYY', 'ACME', 'BEANS', '', 5, ''], 'Warehouse code “BEYY” was not found. Did you mean “BEY”?'],
    'unknown customer' => [['BEY', 'ACM', 'BEANS', '', 5, ''], 'Did you mean “ACME”?'],
    'unknown item' => [['BEY', 'ACME', 'BEANZ', '', 5, ''], 'Item “BEANZ” was not found for customer ACME. Did you mean “BEANS”?'],
    'unit the item doesn’t have' => [['BEY', 'ACME', 'BEANS', '', 5, 'KG'], 'Unit “KG” is not set up for item BEANS (its units:'],
    'no quantity' => [['BEY', 'ACME', 'BEANS', '', '', ''], 'Quantity is missing.'],
    'zero quantity' => [['BEY', 'ACME', 'BEANS', '', 0, ''], 'Quantity must be a whole number above 0'],
    'decimal quantity' => [['BEY', 'ACME', 'BEANS', '', '1.5', ''], 'Quantity must be a whole number above 0 (found “1.5”)'],
    'quantity over 999,999' => [['BEY', 'ACME', 'BEANS', '', 1000000, ''], 'Quantity is too large: at most 999,999 per row (found “1000000”)'],
    'expiry item without expiry' => [['BEY', 'ACME', 'YOGURT', '', 5, ''], 'Item YOGURT tracks expiry: enter the expiry date.'],
    'not a date' => [['BEY', 'ACME', 'YOGURT', '31/02/2027', 5, ''], 'Expiry date “31/02/2027” is not a date.'],
    'serial item without serial' => [['BEY', 'ACME', 'PHONE', '', 1, ''], 'Item PHONE has serial numbers: enter the serial number of this piece.'],
    'serial item with more than 1' => [['BEY', 'ACME', 'PHONE', '', 3, '', 'SN-1'], 'use one row per piece, with quantity 1 (found “3”)'],
]);

it('imports serial items one row per piece', function () {
    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'PHONE', 1, serial: 'SN-1'),
        balanceRow('BEY', 'PHONE', '', serial: 'SN-2'), // no quantity: one piece
        balanceRow('BEY', 'BEANS', 5, serial: 'SN-9'), // not a serial item: left out, with a warning
    ]));
    $rows = $job->rows()->orderBy('row_no')->get();

    expect($rows->pluck('status')->all())->toBe(['VALID', 'VALID', 'WARNING'])
        ->and($rows[2]->text())->toBe(__('imports.warnings.serial_ignored', ['item' => 'BEANS']));

    app(ImportEngine::class)->confirm($job, $this->manager);

    expect($this->stock->balance('PHONE'))->toBe(2.0)
        ->and($this->stock->balance('BEANS'))->toBe(5.0)
        ->and(DB::table('serial_number')->where('status', 'IN_STOCK')->where('warehouse_id', $this->stock->warehouse->id)->orderBy('serial_no')->pluck('serial_no')->all())->toBe(['SN-1', 'SN-2']);

    // Rule 12: the two pieces are one line of the Opening Balance, with both serials.
    $phone = DB::table('stock_transaction_line')->where('product_id', $this->stock->id('PHONE'))->get();
    expect($phone)->toHaveCount(1)
        ->and((float) $phone[0]->qty_base)->toBe(2.0)
        ->and(DB::table('stock_transaction_line_serial')->where('line_id', $phone[0]->id)->count())->toBe(2);
});

it('refuses a serial that is twice in the file or already in stock', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['SN-1'])]);

    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'PHONE', 1, serial: 'SN-1'),
        balanceRow('BEY', 'PHONE', 1, serial: 'SN-2'),
        balanceRow('TRP', 'PHONE', 1, serial: 'SN-2'),
    ]));
    $rows = $job->rows()->orderBy('row_no')->get();

    expect($rows->pluck('status')->all())->toBe(['ERROR', 'ERROR', 'ERROR'])
        ->and($rows[0]->text())->toContain('Serial SN-1 is already in stock.')
        ->and($rows[1]->text())->toContain('Serial SN-2 is also on row '.$rows[2]->row_no.' of this file.')
        ->and($this->stock->balance('PHONE'))->toBe(1.0);
});

it('keeps an expiry item’s stock per expiry date: rows with the same date add up', function () {
    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'YOGURT', 6, expiry: '31/03/2027'),
        balanceRow('BEY', 'YOGURT', 4, expiry: '2027-03-31'),
        balanceRow('BEY', 'YOGURT', 2, expiry: '2027-04-30'),
    ]));

    expect($job->rows()->pluck('status')->unique()->all())->toBe(['VALID']);

    app(ImportEngine::class)->confirm($job, $this->manager);

    expect($this->stock->balance('YOGURT', '2027-03-31'))->toBe(10.0)
        ->and($this->stock->balance('YOGURT', '2027-04-30'))->toBe(2.0)
        // Rule 12: rows of the same item, unit and date are one line (6 + 4), the other date another.
        ->and(DB::table('stock_transaction_line')->where('product_id', $this->stock->id('YOGURT'))->orderBy('line_no')->pluck('qty_base')->map(fn ($q) => (float) $q)->all())->toBe([10.0, 2.0]);
});

it('warns when stock is added to what is already there, or has expired', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)]);

    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'BEANS', 5),
        balanceRow('BEY', 'YOGURT', 6, expiry: now()->subDays(3)->toDateString()),
    ]));
    $rows = $job->rows()->orderBy('row_no')->get();

    expect($rows->pluck('status')->all())->toBe(['WARNING', 'WARNING'])
        ->and($rows[0]->text())->toBe(__('imports.warnings.adds_to_stock', ['item' => 'BEANS', 'warehouse' => 'BEY']))
        ->and($rows[1]->text())->toBe(__('imports.warnings.expired', ['date' => Format::date(now()->subDays(3))]));

    app(ImportEngine::class)->confirm($job, $this->manager);
    expect($this->stock->balance('BEANS'))->toBe(15.0);
});

it('imports only the valid rows of a mixed file', function () {
    $job = uploadBalances($this->manager, balanceFile([
        balanceRow('BEY', 'BEANS', 3),
        balanceRow('SAI', 'BEANS', 3),
        balanceRow('BEY', 'NOPE', 3),
    ]));

    $result = app(ImportEngine::class)->confirm($job, $this->manager);

    expect($result)->toMatchArray(['lines' => 1, 'skipped' => 2])
        ->and($this->stock->balance('BEANS'))->toBe(3.0)
        ->and($this->stock->balance('BEANS', warehouse: $this->saida))->toBe(0.0);
});

it('rechecks the warehouse of the person who confirms', function () {
    $job = uploadBalances($this->manager, balanceFile([balanceRow('TRP', 'BEANS', 3)]));

    // A colleague who works in Tripoli too may confirm…
    $colleague = userWithRole('MANAGER', $this->tripoli);
    $this->actingAs($colleague)->get(route('imports.show', $job->id))->assertOk();

    // …but not one who only works in Beirut: they don't even see the job.
    $beirutOnly = userWithRole('MANAGER', $this->stock->warehouse);
    $this->actingAs($beirutOnly)->get(route('imports.show', $job->id))->assertNotFound();
    $this->actingAs($beirutOnly)->get(route('imports.errors', $job->id))->assertNotFound();
    $this->actingAs($beirutOnly)->get(route('imports'))->assertDontSee('opening.xlsx');

    // And the check is redone with the confirming user's warehouses.
    $this->manager->warehouses()->detach($this->tripoli->id);
    $manager = $this->manager->fresh(); // as on their next request
    expect(fn () => app(ImportEngine::class)->confirm($job, $manager))
        ->toThrow(ImportRefused::class, __('imports.errors.nothing_to_import'));
    expect($job->rows()->sole()->text())->toContain('You are not assigned to warehouse TRP.')
        ->and($this->stock->balance('BEANS', warehouse: $this->tripoli))->toBe(0.0);
});

it('needs both the import and the opening-balance permissions', function () {
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);
    $dataEntry = userWithRole('DATA_ENTRY', $this->stock->warehouse);

    $this->actingAs($operator)->get(route('imports.create', 'opening-balance'))->assertForbidden();
    $this->actingAs($dataEntry)->get(route('imports.create', 'opening-balance'))->assertForbidden();
    $this->actingAs($dataEntry)->get(route('imports.template', 'opening-balance'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('imports.create', 'opening-balance'))->assertOk()->assertSee(__('imports.upload.download_template'));
});

it('lists only the user’s warehouses in the template', function () {
    $response = $this->actingAs($this->manager)->get(route('imports.template', 'opening-balance'))->assertOk();
    $codes = collect(IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(1)->toArray())->flatten()->filter();

    expect($codes)->toContain('BEY')->toContain('TRP')->not->toContain('SAI')
        ->and($codes)->toContain('BEANS (ACME)');
});

it('refuses a service: it has no stock, so no opening balance', function () {
    $pc = Unit::where('code', 'PC')->value('id');
    $service = Product::create(['customer_id' => $this->stock->customer->id, 'sku' => 'PACK', 'name_en' => 'Packing', 'base_unit_id' => $pc, 'is_service' => true]);
    $service->units()->create(['unit_id' => $pc, 'factor_to_base' => 1, 'is_default' => true]);

    $result = uploadBalances($this->manager, balanceFile([balanceRow('BEY', 'PACK', 3)]))->rows()->sole();

    expect($result->status)->toBe('ERROR')
        ->and($result->text())->toContain('Item PACK is a service');
});
