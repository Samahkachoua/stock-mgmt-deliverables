<?php

use App\Livewire\Reports\ReportPage;
use App\Livewire\Stock\StockByExpiryPage;
use App\Livewire\Stock\StockPage;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Reports;
use App\Support\Format;
use App\Support\Permissions;
use App\Support\StorageStatuses;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Exports;
use Tests\Support\ReportData;
use Tests\Support\Stock;

beforeEach(function () {
    $this->data = ReportData::create();
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

/**
 * The Stock by expiry page with these filters, rows loaded.
 *
 * @param  array<string, string>  $filters
 */
function stockByExpiry(User $user, Warehouse $current, array $filters = []): Testable
{
    startIn($user, $current);
    session()->forget('list_state');

    $component = Livewire::actingAs($user)->test(StockByExpiryPage::class);

    return ($filters === [] ? $component : $component->set('filters', $filters))->call('loadRows');
}

/**
 * @return list<array<string, mixed>>
 */
function stockByExpiryRows(Testable $page): array
{
    return collect($page->viewData('rows')->items())->pluck('cells')->all();
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function stockByExpiryColumn(array $rows, string $key): array
{
    return array_map(fn (array $row) => is_array($row[$key]) ? $row[$key]['text'] : $row[$key], $rows);
}

it('shows the Stock page split per batch and expiry date, expired or not', function () {
    $d = $this->data;
    $rows = stockByExpiryRows(stockByExpiry($d->admin, $d->beirut));
    $expired = now()->subDays(2)->toDateString();
    $soon = now()->addDays(10)->toDateString();
    $later = now()->addDays(100)->toDateString();

    // Yogurt has three lots (nearest first); the other items one row each.
    expect(stockByExpiryColumn($rows, 'item'))->toBe(['Canned Beans', 'Phone', 'Rice 5kg', 'Yogurt', 'Yogurt', 'Yogurt'])
        ->and($rows[0])->not->toHaveKey('batch') // no Batch column (still one row per lot)
        ->and(stockByExpiryColumn($rows, 'code'))->toContain('YOGURT')
        ->and(array_column(array_column($rows, 'qty'), 'value'))->toBe([28.0, 2.0, 9.0, 2.0, 12.0, 6.0])
        ->and(array_column(array_slice(array_column($rows, 'expiry'), 3), 'text'))->toBe([Format::date(now()->subDays(2)), Format::date(now()->addDays(10)), Format::date(now()->addDays(100))])
        ->and($rows[3])->not->toHaveKey('expiry_status')
        ->and($rows[0]['expiry'])->toBe('');

    // SKU first, then the name; one Unit column and plain numbers after it.
    expect(array_slice(array_keys(Reports::get('stock-by-expiry')->columns()), 0, 2))->toBe(['code', 'item'])
        ->and(array_keys($rows[0]))->toBe(array_keys(Reports::get('stock-by-expiry')->columns()))
        ->and($rows[0]['unit'])->toBe(Format::unit('PC'))
        ->and($rows[0]['qty']['text'])->toBe(Format::qty(28))
        ->and($rows[4]['available']['text'])->toBe(Format::qty(12));
});

it('adds up the same totals as the Stock page', function () {
    $d = $this->data;
    $page = stockByExpiry($d->admin, $d->beirut);
    $totals = $page->viewData('totals');

    expect($totals['rows'])->toBe(6)
        ->and($totals['qty'])->toBe(28.0 + 2.0 + 9.0 + 20.0);

    $page->assertSee(trans_choice('reports.stock_on_hand.footer_total', 6, ['count' => Format::qty(6)]));
});

it('filters by item, batch, customer and expiring within 30 days', function () {
    $d = $this->data;
    $soonBatch = Stock::batch(now()->addDays(10)->toDateString());

    expect(stockByExpiryColumn(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['search' => 'yogurt'])), 'item'))->toHaveCount(3)
        ->and(stockByExpiryColumn(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['search' => strtolower($soonBatch)])), 'expiry'))->toBe([Format::date(now()->addDays(10))])
        ->and(stockByExpiryColumn(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['customer' => $d->cedar->id])), 'item'))->toBe(['Rice 5kg'])
        ->and(array_column(array_column(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['expiring' => '1'])), 'qty'), 'value'))->toBe([2.0, 12.0]);
});

it('exports to Excel grouped per item, with collapsible lots and a Total per item', function () {
    $d = $this->data;
    $page = stockByExpiry($d->admin, $d->beirut);
    $download = Exports::fromDownload($page->call('export', 'xlsx'));
    [$header, $rows] = Exports::xlsxContent($download['content']);
    $sheet = Exports::xlsxSheet($download['content']);
    $piece = Format::unit('PC');

    expect($header)->toBe(array_map(fn (array $column) => __($column['label']), array_values(Reports::get('stock-by-expiry')->columns())));

    // SKU, item, customer, warehouse, storage once per item; Total rows after each; grand total last.
    expect(array_column($rows, 0))->toBe(['BEANS', 'BEANS Total', 'PHONE', 'PHONE Total', 'RICE', 'RICE Total', 'YOGURT', null, null, 'YOGURT Total', 'Grand total'])
        ->and(array_column($rows, 1))->toBe(['Canned Beans', null, 'Phone', null, 'Rice 5kg', null, 'Yogurt', null, null, null, null])
        ->and(array_column($rows, 3))->toBe(['Beirut Main', null, 'Beirut Main', null, 'Beirut Main', null, 'Beirut Main', null, null, null, null])
        // Yogurt's three lots, nearest first, then their total.
        ->and(array_slice(array_column($rows, 5), 6, 4))->toBe([Format::date(now()->subDays(2)), Format::date(now()->addDays(10)), Format::date(now()->addDays(100)), null])
        ->and(array_column($rows, 7))->toBe([28.0, 28.0, 2.0, 2.0, 9.0, 9.0, 2.0, 12.0, 6.0, 20.0, 59.0])
        ->and(array_unique(array_column($rows, 6)))->toBe([$piece]);

    // The grand total is the page's footer total.
    expect((float) $rows[10][7])->toBe($page->viewData('totals')['qty'])
        ->and((float) $rows[10][9])->toBe($page->viewData('totals')['available']);

    // Lot rows are the outline (collapsible to their Total); totals are not, and are bold.
    $levels = array_map(fn (int $row) => $sheet->getRowDimension($row)->getOutlineLevel(), range(2, 12));
    expect($levels)->toBe([1, 0, 1, 0, 1, 0, 1, 1, 1, 0, 0])
        ->and($sheet->getShowSummaryBelow())->toBeTrue()
        ->and($sheet->getStyle('A3')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getStyle('J12')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getStyle('A8')->getFont()->getBold())->toBeTrue()  // Yogurt's SKU on its first lot
        ->and($sheet->getStyle('F8')->getFont()->getBold())->toBeFalse() // its expiry date is not
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:J12')
        ->and($sheet->getFreezePane())->toBe('A2');
});

it('groups the Excel export with the columns the user left visible', function () {
    $d = $this->data;
    $page = stockByExpiry($d->admin, $d->beirut)->set('hiddenColumns', ['code', 'reserved']);
    [$header, $rows] = Exports::xlsxContent(Exports::fromDownload($page->call('export', 'xlsx'))['content']);

    expect($header)->not->toContain(__('reports.columns.sku'))
        ->and($header)->not->toContain(__('reports.columns.reserved'))
        ->and(array_column($rows, 0))->toBe(['Canned Beans', 'Canned Beans Total', 'Phone', 'Phone Total', 'Rice 5kg', 'Rice 5kg Total', 'Yogurt', null, null, 'Yogurt Total', 'Grand total'])
        ->and($rows[9])->toHaveCount(count($header))
        ->and($rows[9][array_search(__('reports.columns.qty_base'), $header, true)])->toBe(20.0)
        ->and($rows[9][array_search(__('reports.columns.available'), $header, true)])->toBe(20.0);
});

it('groups the Excel export of every warehouse per warehouse, and of a filter', function () {
    $d = $this->data;

    // All my warehouses: Rice in Beirut and in Tripoli stay two groups.
    [, $all] = Exports::xlsxContent(Exports::fromDownload(stockByExpiry($d->admin, $d->beirut, ['warehouse' => 'all'])->call('export', 'xlsx'))['content']);
    $rice = array_values(array_filter($all, fn (array $row) => in_array($row[0], ['RICE', 'RICE Total'], true)));

    expect(array_column($rice, 0))->toBe(['RICE', 'RICE Total', 'RICE', 'RICE Total'])
        ->and(array_column($rice, 3))->toBe(['Beirut Main', null, 'Tripoli', null])
        ->and(array_column($rice, 7))->toBe([9.0, 9.0, 8.0, 8.0])
        ->and(end($all)[7])->toBe(59.0 + 8.0);

    // Filtered on one item: that item's lots and total only.
    [, $yogurt] = Exports::xlsxContent(Exports::fromDownload(stockByExpiry($d->admin, $d->beirut, ['search' => 'yogurt'])->call('export', 'xlsx'))['content']);
    expect(array_column($yogurt, 0))->toBe(['YOGURT', null, null, 'YOGURT Total', 'Grand total'])
        ->and(array_column($yogurt, 7))->toBe([2.0, 12.0, 6.0, 20.0, 20.0]);
});

it('exports the grouped sheet right to left in Arabic, with Arabic totals', function () {
    $d = $this->data;
    $d->admin->update(['preferred_lang' => 'ar']);
    app()->setLocale('ar');

    $download = Exports::fromDownload(stockByExpiry($d->admin->fresh(), $d->beirut)->call('export', 'xlsx'));
    [$header, $rows, $rtl] = Exports::xlsxContent($download['content']);

    expect($rtl)->toBeTrue()
        ->and($header[0])->toBe('رمز الصنف')
        ->and($rows[1][0])->toBe('إجمالي BEANS')
        ->and(end($rows)[0])->toBe('الإجمالي العام');
});

it('leaves other reports’ Excel exports flat', function () {
    $d = $this->data;
    startIn($d->admin, $d->beirut);
    session()->forget('list_state');
    $page = Livewire::actingAs($d->admin)->test(ReportPage::class, ['report' => 'damage'])->set('filters', ['warehouse' => 'all']);
    $download = Exports::fromDownload($page->call('export', 'xlsx'));
    [, $rows] = Exports::xlsxContent($download['content']);
    $sheet = Exports::xlsxSheet($download['content']);

    expect($rows)->toHaveCount($page->viewData('rows')->total())
        ->and($sheet->getRowDimension(2)->getOutlineLevel())->toBe(0)
        ->and($sheet->getAutoFilter()->getRange())->toBe('');
});

it('keeps other warehouses and customers out, on screen and in the export', function () {
    $d = $this->data;
    $tripoli = stockByExpiry($d->tripoliOperator, $d->tripoli, ['warehouse' => $d->beirut->id]);
    $portal = stockByExpiry($d->acmePortal, $d->beirut, ['warehouse' => 'all']);
    $download = Exports::fromDownload($portal->call('export', 'xlsx'));
    [, $exported] = Exports::xlsxContent($download['content']);
    // The export adds a Total row per item and a grand total: the lot rows are the grouped (outline) ones.
    $sheet = Exports::xlsxSheet($download['content']);
    $lots = array_filter(range(2, count($exported) + 1), fn (int $row) => $sheet->getRowDimension($row)->getOutlineLevel() === 1);

    expect(array_unique(stockByExpiryColumn(stockByExpiryRows($tripoli), 'warehouse')))->toBe(['Tripoli'])
        ->and(stockByExpiryColumn(stockByExpiryRows($portal), 'item'))->not->toContain('Rice 5kg')
        ->and(array_filter(array_column($exported, 1)))->not->toContain('Rice 5kg')
        ->and($lots)->toHaveCount($portal->viewData('rows')->total());
});

it('sits in the sidebar under Stock, with its own permission', function () {
    $d = $this->data;

    $this->actingAs($d->tripoliOperator)->get(route('stock-by-expiry'))->assertOk();
    $this->actingAs($d->tripoliOperator)->get(route('stock'))->assertSee('href="'.route('stock-by-expiry').'"', false);

    $role = DB::table('role')->where('code', 'OPERATOR')->value('id');
    DB::table('role_permission')->where('role_id', $role)
        ->whereIn('permission_id', DB::table('permission')->where('code', 'report.stock_by_expiry.view')->select('id'))
        ->delete();
    Permissions::flush();

    $this->actingAs(User::find($d->tripoliOperator->id))->get(route('stock-by-expiry'))->assertForbidden();
});

it('opens an item\'s stock from Stock and Stock by expiry only with its own permission', function () {
    $d = $this->data;
    $link = route('stock.item', [$d->stock->id('BEANS'), $d->beirut->id]);
    $viewer = userWithPermissions($d->beirut, ['stock.view', 'report.stock_by_expiry.view']);
    $opener = userWithPermissions($d->beirut, ['stock.view', 'report.stock_by_expiry.view', 'stock.item.view']);

    foreach ([StockPage::class, StockByExpiryPage::class] as $page) {
        $without = Livewire::actingAs($viewer)->test($page)->call('loadRows');
        $with = Livewire::actingAs($opener)->test($page)->call('loadRows');

        // Without it the rows are still listed, with no arrow and no link.
        expect($without->viewData('rows')->total())->toBeGreaterThan(0)
            ->and(collect($without->viewData('rows')->items())->pluck('link')->filter()->all())->toBe([])
            ->and(collect($with->viewData('rows')->items())->pluck('link'))->toContain($link);
        $without->assertDontSeeHtml('href="'.$link.'"');
        $with->assertSeeHtml('href="'.$link.'"');
    }

    $this->actingAs($viewer)->get($link)->assertForbidden();
    $this->actingAs($opener)->get($link)->assertOk();
});

it('shows each item\'s storage status and filters by it', function () {
    $d = $this->data;
    DB::table('product')->where('sku', 'YOGURT')->update(['storage_status' => 'CHILLED']);

    $chilled = stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['storage' => 'CHILLED']));

    expect(stockByExpiryColumn($chilled, 'item'))->toBe(['Yogurt', 'Yogurt', 'Yogurt'])
        ->and(stockByExpiryColumn($chilled, 'storage'))->each->toBe(StorageStatuses::name('CHILLED'))
        ->and(stockByExpiryColumn(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['storage' => 'none'])), 'item'))->toBe(['Canned Beans', 'Phone', 'Rice 5kg']);
});
