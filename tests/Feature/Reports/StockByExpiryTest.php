<?php

use App\Livewire\Stock\StockByExpiryPage;
use App\Models\User;
use App\Models\Warehouse;
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

it('keeps other warehouses and customers out, on screen and in the export', function () {
    $d = $this->data;
    $tripoli = stockByExpiry($d->tripoliOperator, $d->tripoli, ['warehouse' => $d->beirut->id]);
    $portal = stockByExpiry($d->acmePortal, $d->beirut, ['warehouse' => 'all']);
    $download = Exports::fromDownload($portal->call('export', 'xlsx'));
    [, $exported] = Exports::xlsxContent($download['content']);

    expect(array_unique(stockByExpiryColumn(stockByExpiryRows($tripoli), 'warehouse')))->toBe(['Tripoli'])
        ->and(stockByExpiryColumn(stockByExpiryRows($portal), 'item'))->not->toContain('Rice 5kg')
        ->and($exported)->toHaveCount($portal->viewData('rows')->total());
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

it('shows each item\'s storage status and filters by it', function () {
    $d = $this->data;
    DB::table('product')->where('sku', 'YOGURT')->update(['storage_status' => 'CHILLED']);

    $chilled = stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['storage' => 'CHILLED']));

    expect(stockByExpiryColumn($chilled, 'item'))->toBe(['Yogurt', 'Yogurt', 'Yogurt'])
        ->and(stockByExpiryColumn($chilled, 'storage'))->each->toBe(StorageStatuses::label('CHILLED'))
        ->and(stockByExpiryColumn(stockByExpiryRows(stockByExpiry($d->admin, $d->beirut, ['storage' => 'none'])), 'item'))->toBe(['Canned Beans', 'Phone', 'Rice 5kg']);
});
