<?php

use App\Imports\ImportEngine;
use App\Imports\ImportRefused;
use App\Imports\Imports;
use App\Jobs\ReadImportFile;
use App\Livewire\Imports\ImportJobPage;
use App\Livewire\Imports\ImportUpload;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Exports;
use Tests\Support\Spreadsheets;
use Tests\Support\Stock;

beforeEach(function () {
    Storage::fake(config('imports.disk'));
    $this->stock = Stock::create(); // customer ACME with BEANS, YOGURT, PHONE
    $this->user = userWithRole('DATA_ENTRY', $this->stock->warehouse);
});

/**
 * The item template's headers, in English.
 *
 * @return list<string>
 */
function itemHeaders(?string $locale = 'en'): array
{
    return array_map(fn ($column) => $column->header($locale), Imports::get('items')->columns());
}

/**
 * An item row in template order; $values overrides by column key.
 *
 * @param  array<string, string>  $values
 * @return list<string>
 */
function itemRow(array $values): array
{
    $row = array_merge([
        'customer_code' => 'ACME', 'item_code' => 'RICE-5K', 'name_en' => 'Rice 5kg', 'name_ar' => 'أرز', 'name_fr' => 'Riz',
        'base_unit' => 'PC', 'tracks_expiry' => 'N', 'tracks_serial' => 'N', 'storage_status' => '', 'approval_limit' => '',
        'alternate_unit_1' => '', 'factor_1' => '', 'alternate_unit_2' => '', 'factor_2' => '', 'alternate_unit_3' => '', 'factor_3' => '',
    ], $values);

    return array_map(fn ($column) => $row[$column->key], Imports::get('items')->columns());
}

/**
 * Uploads through the wizard (the queue runs synchronously in tests).
 */
function uploadItems(User $user, UploadedFile $file): ImportJob
{
    Livewire::actingAs($user)->test(ImportUpload::class, ['type' => 'items'])
        ->set('file', $file)
        ->call('send')
        ->assertHasNoErrors();

    return ImportJob::query()->latest('created_at')->latest('id')->firstOrFail();
}

/**
 * @return array<int, array{status: string, message: string|null}> by row number
 */
function rowResults(ImportJob $job): array
{
    return $job->rows()->orderBy('row_no')->get()
        ->mapWithKeys(fn (ImportRow $row) => [$row->row_no => ['status' => $row->status, 'message' => $row->text()]])
        ->all();
}

it('imports a valid file: units, tracking, limits, and an audit entry per item', function () {
    $file = Spreadsheets::upload(itemHeaders(), [
        itemRow(['item_code' => 'rice-5k', 'alternate_unit_1' => 'BOX', 'factor_1' => '12', 'alternate_unit_2' => 'PLT', 'factor_2' => '480', 'approval_limit' => '24']),
        itemRow(['item_code' => 'MILK-1L', 'name_en' => 'Milk 1L', 'tracks_expiry' => 'Y']),
        itemRow(['item_code' => 'TV-55', 'name_en' => 'TV 55"', 'tracks_serial' => 'yes']),
    ]);

    $job = uploadItems($this->user, $file);

    // Checked, nothing saved yet.
    expect($job->status)->toBe('VALIDATED')
        ->and(app(ImportEngine::class)->counts($job))->toBe(['ready' => 3, 'warnings' => 0, 'errors' => 0, 'imported' => 0])
        ->and(Product::where('sku', 'RICE-5K')->exists())->toBeFalse();

    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $job->id])
        ->assertSee(trans_choice('imports.check.import_button', 3, ['count' => '3']))
        ->call('import')
        ->assertSet('problem', null)
        ->assertSee(trans_choice('imports.done.items', 3, ['count' => '3']));

    $rice = Product::where('sku', 'RICE-5K')->with('units.unit')->sole();
    expect($rice->converter()->factors())->toBe(['PLT' => 480.0, 'BOX' => 12.0, 'PC' => 1.0])
        ->and($rice->name_ar)->toBe('أرز')
        ->and((float) $rice->approval_threshold_qty)->toBe(24.0)
        ->and(Product::where('sku', 'MILK-1L')->sole()->track_expiry)->toBeTrue()
        ->and(Product::where('sku', 'TV-55')->sole()->track_serial)->toBeTrue()
        ->and($job->fresh()->status)->toBe('CONFIRMED')
        ->and($job->fresh()->result['items'])->toBe(3)
        ->and(DB::table('audit_log')->where('entity', 'product')->where('action', 'CREATE')->where('user_id', $this->user->id)->count())->toBe(3)
        ->and(DB::table('audit_log')->where('entity', 'import_job')->where('entity_id', $job->id)->exists())->toBeTrue();
});

it('catches every kind of mistake with a plain message', function (array $values, string $expected) {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow($values)]));
    $row = rowResults($job)[2];

    expect($row['status'])->toBe('ERROR')
        ->and($row['message'])->toContain($expected);
})->with([
    'unknown customer, with a suggestion' => [['customer_code' => 'ACMME'], 'Customer code “ACMME” was not found. Did you mean “ACME”?'],
    'unknown unit, with a suggestion' => [['base_unit' => 'PCS'], 'Base unit “PCS” was not found. Did you mean “PC”?'],
    'unknown alternate unit' => [['alternate_unit_1' => 'BXO', 'factor_1' => '12'], 'Did you mean “BOX”?'],
    'item code already in the database' => [['item_code' => 'BEANS'], 'Item BEANS already exists for customer ACME.'],
    'missing name' => [['name_en' => ''], 'Name (English) is missing.'],
    'missing customer' => [['customer_code' => ''], 'Customer code is missing.'],
    'bad Y/N flag' => [['tracks_expiry' => 'maybe'], 'Tracks expiry (Y/N) must be Y or N (found “maybe”).'],
    'factor not above 1' => [['alternate_unit_1' => 'BOX', 'factor_1' => '1'], 'The factor for BOX must be more than 1'],
    'factor not a number' => [['alternate_unit_1' => 'BOX', 'factor_1' => 'twelve'], 'The factor for BOX must be a number'],
    'alternate unit that is the base unit' => [['alternate_unit_1' => 'PC', 'factor_1' => '6'], 'PC is already the base unit.'],
    'alternate units on a serial item' => [['tracks_serial' => 'Y', 'alternate_unit_1' => 'BOX', 'factor_1' => '12'], 'Items with serial numbers use only their base unit'],
    'serial and expiry together' => [['tracks_serial' => 'Y', 'tracks_expiry' => 'Y'], 'An item can’t have both serial numbers and an expiry date yet.'],
    'code with spaces' => [['item_code' => 'RICE 5K'], 'may only use letters, digits'],
    'negative approval limit' => [['approval_limit' => '-3'], 'Approval limit must be a number of 0 or more'],
    'unknown storage status, with a suggestion' => [['storage_status' => 'CHILLD'], 'Storage status “CHILLD” was not found. Did you mean “CHILLED”?'],
]);

it('finds the same item code twice in a file', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [
        itemRow(['item_code' => 'RICE-5K']),
        itemRow(['item_code' => 'OIL-1L']),
        itemRow(['item_code' => 'rice-5k']),
    ]));

    expect(rowResults($job)[2]['message'])->toContain('Item code RICE-5K is also on row 4 of this file.')
        ->and(rowResults($job)[4]['message'])->toContain('also on row 2')
        ->and(rowResults($job)[3]['status'])->toBe('VALID');
});

it('warns about missing translations but still imports the row', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow(['name_ar' => ''])]));

    expect(rowResults($job)[2])->toBe(['status' => 'WARNING', 'message' => __('imports.warnings.missing_names')]);

    app(ImportEngine::class)->confirm($job, $this->user);
    expect(Product::where('sku', 'RICE-5K')->exists())->toBeTrue();
});

it('imports only the valid rows of a mixed file, and shows only the problem rows', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [
        itemRow(['item_code' => 'RICE-5K']),
        itemRow(['item_code' => 'BAD', 'base_unit' => 'XX']),
        itemRow(['item_code' => 'OIL-1L', 'name_en' => 'Oil']),
    ]));

    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $job->id])
        ->assertSee('Base unit “XX” was not found.')
        // Only the problem row is listed, not the ready ones.
        ->assertDontSee('RICE-5K')
        ->assertSee(trans_choice('imports.check.errors_left_out', 1, ['count' => '1']))
        ->call('import')
        ->assertSee(trans_choice('imports.done.skipped', 1, ['count' => '1']));

    expect(Product::whereIn('sku', ['RICE-5K', 'OIL-1L', 'BAD'])->pluck('sku')->sort()->values()->all())->toBe(['OIL-1L', 'RICE-5K'])
        ->and(rowResults($job)[3]['status'])->toBe('ERROR')
        ->and($job->fresh()->result)->toMatchArray(['items' => 2, 'rows' => 2, 'skipped' => 1]);
});

it('reports duplicates when the same file is uploaded again, instead of duplicating', function () {
    $rows = [itemRow(['item_code' => 'RICE-5K']), itemRow(['item_code' => 'OIL-1L'])];

    app(ImportEngine::class)->confirm(uploadItems($this->user, Spreadsheets::upload(itemHeaders(), $rows)), $this->user);
    $again = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), $rows));

    expect(array_column(rowResults($again), 'status'))->toBe(['ERROR', 'ERROR'])
        ->and(rowResults($again)[2]['message'])->toContain('Item RICE-5K already exists for customer ACME.');

    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $again->id])
        ->assertSee(trans_choice('imports.check.import_button', 0))
        ->call('import')
        ->assertSet('problem', __('imports.errors.nothing_to_import'));

    expect(Product::where('sku', 'RICE-5K')->count())->toBe(1);
});

it('checks again on import, so a stale check can’t slip through', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow(['item_code' => 'RICE-5K']), itemRow(['item_code' => 'OIL-1L'])]));
    expect(app(ImportEngine::class)->counts($job)['ready'])->toBe(2);

    // Someone adds RICE-5K by hand between the check and the import.
    $copy = $this->stock->items['BEANS']->replicate()->fill(['sku' => 'RICE-5K']);
    $copy->save();

    $result = app(ImportEngine::class)->confirm($job, $this->user);

    expect($result['items'])->toBe(1)
        ->and(rowResults($job)[2]['status'])->toBe('ERROR')
        ->and(Product::where('sku', 'RICE-5K')->count())->toBe(1);
});

it('imports only once', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow([])]));
    app(ImportEngine::class)->confirm($job, $this->user);

    expect(fn () => app(ImportEngine::class)->confirm($job, $this->user))->toThrow(ImportRefused::class, __('imports.errors.already_imported'));
});

it('reads headers in any language and Excel’s own Y/N and number cells', function () {
    $file = Spreadsheets::upload(itemHeaders('fr'), [itemRow(['tracks_expiry' => 'oui', 'alternate_unit_1' => 'BOX', 'factor_1' => 12])]);

    $job = uploadItems($this->user, $file);
    app(ImportEngine::class)->confirm($job, $this->user);

    expect(Product::where('sku', 'RICE-5K')->sole()->track_expiry)->toBeTrue();
});

it('refuses what isn’t an .xlsx template, too many rows, and empty files', function () {
    Livewire::actingAs($this->user)->test(ImportUpload::class, ['type' => 'items'])
        ->set('file', UploadedFile::fake()->createWithContent('items.csv', "a,b\n1,2"))
        ->call('send')
        ->assertHasErrors(['file'])
        ->assertSee(__('imports.errors.not_xlsx'));

    $wrong = uploadItems($this->user, Spreadsheets::upload(['Code', 'Description'], [['A', 'B']]));
    expect($wrong->status)->toBe('FAILED')
        ->and($wrong->error_message)->toContain('This doesn’t look like the Items template')
        ->and($wrong->error_message)->toContain('Customer code, Item code, Name (English), Base unit');

    config(['imports.max_rows' => 3]);
    $tooMany = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), array_map(fn ($i) => itemRow(['item_code' => 'X'.$i]), range(1, 4))));
    expect($tooMany->status)->toBe('FAILED')
        ->and($tooMany->error_message)->toBe(__('imports.errors.too_many_rows', ['max' => '3']))
        ->and($tooMany->rows()->count())->toBe(0);

    $empty = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), []));
    expect($empty->error_message)->toBe(__('imports.errors.empty'));

    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $tooMany->id])
        ->assertSee(__('imports.errors.too_many_rows', ['max' => '3']))
        ->assertDontSee(__('imports.check.check_again'));
});

it('allows 5,000 rows by default', function () {
    expect(config('imports.max_rows'))->toBe(5000);
});

it('downloads the template in the user’s language with the codes on a second sheet', function () {
    $this->user->update(['preferred_lang' => 'ar']);
    $response = $this->actingAs($this->user)->get(route('imports.template', 'items'))->assertOk();

    $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
    $template = $book->getSheet(0)->toArray()[0];
    $codes = collect($book->getSheet(1)->toArray())->flatten()->filter()->all();

    expect($template[0])->toBe(__('imports.columns.customer_code', [], 'ar').' *')
        ->and($template)->toHaveCount(count(Imports::get('items')->columns()))
        ->and($book->getSheet(0)->getRightToLeft())->toBeTrue()
        ->and($codes)->toContain('ACME')->toContain('Acme Foods')->toContain('BOX')->toContain('PLT');
});

it('gives an error report with the problem rows as uploaded and a Message column', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [
        itemRow(['item_code' => 'RICE-5K']),
        itemRow(['item_code' => 'BAD', 'base_unit' => 'XX']),
    ]));

    [$header, $rows] = Exports::xlsx($this->actingAs($this->user)->get(route('imports.errors', $job->id))->assertOk());

    expect(end($header))->toBe(__('imports.check.message'))
        ->and($rows)->toHaveCount(1)
        ->and($rows[0][1])->toBe('BAD')
        ->and(end($rows[0]))->toContain('Base unit “XX” was not found.');
});

it('is only for users who may import items', function () {
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);

    $this->actingAs($operator)->get(route('imports'))->assertForbidden();
    $this->actingAs($operator)->get(route('imports.create', 'items'))->assertForbidden();
    $this->actingAs($operator)->get(route('imports.template', 'items'))->assertForbidden();

    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow([])]));
    $this->actingAs($operator)->get(route('imports.show', $job->id))->assertForbidden();
    $this->actingAs($operator)->get(route('imports.errors', $job->id))->assertForbidden();

    // Data entry may import items but not post opening balances.
    $this->actingAs($this->user)->get(route('imports'))->assertOk()->assertSee(__('imports.types.items.title'))
        ->assertDontSee(route('imports.create', 'opening-balance'));
    $this->actingAs($this->user)->get(route('imports.create', 'opening-balance'))->assertForbidden();
});

it('lists past imports with who, when and the result', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow([]), itemRow(['item_code' => 'BAD', 'base_unit' => 'XX'])], 'march-items.xlsx'));
    app(ImportEngine::class)->confirm($job, $this->user);

    $this->actingAs($this->user)->get(route('imports'))
        ->assertOk()
        ->assertSee('march-items.xlsx')
        ->assertSee($this->user->full_name)
        ->assertSee(trans_choice('imports.list.imported', 1, ['count' => '1']).' · '.trans_choice('imports.list.skipped', 1, ['count' => '1']));
});

it('stores the upload and reads it in a queued job, showing progress meanwhile', function () {
    Queue::fake();

    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [itemRow([])]));

    expect($job->status)->toBe('UPLOADED')
        ->and($job->rows()->count())->toBe(0);
    Storage::disk(config('imports.disk'))->assertExists($job->path);
    Queue::assertPushed(ReadImportFile::class, fn ($queued) => $queued->jobId === $job->id);

    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $job->id])
        ->assertSee(__('imports.reading.opening'))
        ->assertSeeHtml('wire:poll');

    (new ReadImportFile($job->id))->handle(app(ImportEngine::class));

    expect($job->fresh()->processed_rows)->toBe(1)
        ->and($job->fresh()->total_rows)->toBe(1);
    Livewire::actingAs($this->user)->test(ImportJobPage::class, ['job' => $job->id])
        ->assertSee(__('imports.check.ready'))
        ->assertDontSeeHtml('wire:poll');
});

it('shows the wizard in Arabic, right to left', function () {
    $this->user->update(['preferred_lang' => 'ar']);
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders('ar'), [itemRow(['base_unit' => 'XX'])]));

    foreach ([route('imports'), route('imports.create', 'items'), route('imports.show', $job->id)] as $url) {
        $this->actingAs($this->user)->get($url)->assertOk()->assertSee('dir="rtl"', false)->assertSee(__('imports.steps.check', [], 'ar'));
    }

    // Messages are shown in each viewer's language, whoever uploaded the file.
    expect($job->rows()->sole()->text('en'))->toContain('Base unit “XX” was not found.');
    expect($job->rows()->sole()->text('ar'))->toContain(__('imports.errors.unknown', ['column' => __('imports.columns.base_unit', [], 'ar'), 'value' => 'XX'], 'ar'));
});

it('imports the storage status, written as a code in any case, and leaves it empty when blank', function () {
    $job = uploadItems($this->user, Spreadsheets::upload(itemHeaders(), [
        itemRow(['item_code' => 'RICE-5K', 'storage_status' => 'ambient']),
        itemRow(['item_code' => 'ICE-1K', 'storage_status' => 'Deep frozen']),
        itemRow(['item_code' => 'OIL-1L', 'storage_status' => '']),
    ]));

    expect(app(ImportEngine::class)->counts($job)['ready'])->toBe(3);
    app(ImportEngine::class)->confirm($job, $this->user);

    expect(Product::where('sku', 'RICE-5K')->value('storage_status'))->toBe('AMBIENT')
        ->and(Product::where('sku', 'ICE-1K')->value('storage_status'))->toBe('DEEP_FROZEN')
        ->and(Product::where('sku', 'OIL-1L')->value('storage_status'))->toBeNull();
});
