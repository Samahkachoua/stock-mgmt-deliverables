<?php

use App\Livewire\Reports\ReportPage;
use App\Livewire\Stock\StockPage;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Reports;
use App\Services\Stock\PostingService;
use App\Support\Format;
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
 * The report page as the user leaves it after choosing these filters (on the
 * page, never in the URL). Starts from an empty remembered state.
 *
 * @param  array<string, string>  $filters
 */
function reportPage(User $user, Warehouse $current, string $report, array $filters = []): Testable
{
    startIn($user, $current);
    session()->forget('list_state');

    $component = Livewire::actingAs($user)
        ->test($report === 'stock-on-hand' ? StockPage::class : ReportPage::class, ['report' => $report]);

    return $filters === [] ? $component : $component->set('filters', $filters);
}

/**
 * The report on screen: total rows, and the cells of the first page.
 *
 * @param  array<string, string>  $filters
 * @return array{total: int, rows: list<array<string, mixed>>, summary: list<array{label: string, value: string}>, missing: string|null}
 */
function onScreen(User $user, Warehouse $current, string $report, array $filters = []): array
{
    $component = reportPage($user, $current, $report, $filters);
    $rows = $component->viewData('rows');

    return [
        'total' => $rows->total(),
        'rows' => collect($rows->items())->pluck('cells')->all(),
        'summary' => $component->viewData('summary'),
        'missing' => $component->viewData('missing'),
    ];
}

/**
 * The Excel export of the page with these filters: data rows keyed by column.
 *
 * @param  array<string, string>  $filters
 * @return list<array<string, mixed>>
 */
function exported(User $user, Warehouse $current, string $report, array $filters = []): array
{
    $download = Exports::fromDownload(reportPage($user, $current, $report, $filters)->call('export', 'xlsx'));
    [, $rows] = Exports::xlsxContent($download['content']);
    $keys = array_keys(Reports::get($report)->columns());

    return array_map(fn (array $row) => array_combine($keys, $row), $rows);
}

/**
 * The text of the PDF export of the page with these filters.
 *
 * @param  array<string, string>  $filters
 */
function exportedPdf(User $user, Warehouse $current, string $report, array $filters = []): string
{
    $download = Exports::fromDownload(reportPage($user, $current, $report, $filters)->call('export', 'pdf'));
    expect($download['name'])->toEndWith('.pdf');

    return Exports::pdfText($download['content']);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function column(array $rows, string $key): array
{
    return array_map(fn (array $row) => is_array($row[$key]) ? $row[$key]['text'] : $row[$key], $rows);
}

// Stock on hand (the Stock page)

it('shows stock on hand with the larger-unit breakdown, in transit and nearest expiry', function () {
    $d = $this->data;
    $here = onScreen($d->admin, $d->beirut, 'stock-on-hand');

    expect($here['total'])->toBe(4)
        ->and(column($here['rows'], 'item'))->toBe(['Canned Beans', 'Phone', 'Rice 5kg', 'Yogurt'])
        ->and(array_column(array_column($here['rows'], 'qty'), 'value'))->toBe([28.0, 2.0, 9.0, 20.0])
        ->and($here['rows'][0]['breakdown']['text'])->toBe('2 Box + 4 Piece')
        ->and($here['rows'][3]['expiry']['tone'])->toBe('rejected'); // part of it has expired

    // All my warehouses: Tripoli adds its rice, and the beans on their way there.
    $all = onScreen($d->admin, $d->beirut, 'stock-on-hand', ['warehouse' => 'all']);
    $arriving = collect($all['rows'])->first(fn ($row) => $row['item']['text'] === 'Canned Beans' && $row['warehouse'] === 'Tripoli');

    expect($all['total'])->toBe(6)
        ->and($arriving['qty']['value'])->toBe(0.0)
        ->and($arriving['in_transit']['value'])->toBe(12.0);
});

it('filters the stock by item, customer and expiring within 30 days', function () {
    $d = $this->data;

    expect(onScreen($d->admin, $d->beirut, 'stock-on-hand', ['search' => 'rice'])['total'])->toBe(1)
        ->and(onScreen($d->admin, $d->beirut, 'stock-on-hand', ['customer' => $d->cedar->id])['total'])->toBe(1)
        ->and(column(onScreen($d->admin, $d->beirut, 'stock-on-hand', ['expiring' => '1'])['rows'], 'item'))->toBe(['Yogurt']);
});

it('keeps the stock of other warehouses and customers out, on screen and in the export', function (string $who, string $current, array $filters, int $expected) {
    $d = $this->data;
    $user = $d->{$who};
    $warehouse = $d->{$current};
    // Placeholders for ids that only exist once the data is created.
    $filters = array_map(fn (string $value) => $value === 'BEIRUT' ? $d->beirut->id : $value, $filters);

    expect(onScreen($user, $warehouse, 'stock-on-hand', $filters)['total'])->toBe($expected)
        ->and(exported($user, $warehouse, 'stock-on-hand', $filters))->toHaveCount($expected);
})->with([
    'Beirut manager, all warehouses' => ['beirutManager', 'beirut', ['warehouse' => 'all'], 4],
    'Tripoli operator' => ['tripoliOperator', 'tripoli', [], 2],
    'Tripoli operator asking for Beirut' => ['tripoliOperator', 'tripoli', ['warehouse' => 'BEIRUT'], 2],
    'Acme’s portal login' => ['acmePortal', 'beirut', ['warehouse' => 'all'], 3],
]);

it('ignores a warehouse the user is not assigned to', function () {
    $d = $this->data;
    $rows = onScreen($d->tripoliOperator, $d->tripoli, 'stock-on-hand', ['warehouse' => $d->beirut->id])['rows'];

    expect(array_unique(column($rows, 'warehouse')))->toBe(['Tripoli'])
        ->and(array_unique(column(exported($d->tripoliOperator, $d->tripoli, 'stock-on-hand', ['warehouse' => $d->beirut->id]), 'warehouse')))->toBe(['Tripoli']);
});

// Item stock details

it('shows an item’s stock per expiry date, serials and last movements with the balance after each', function () {
    $d = $this->data;
    $this->actingAs($d->admin);

    $this->get(route('stock.item', [$d->stock->id('YOGURT'), $d->beirut->id]))
        ->assertOk()
        ->assertSeeInOrder([Format::date(now()->subDays(2)), Format::date(now()->addDays(10)), Format::date(now()->addDays(100))])
        ->assertSee(__('reports.item.expired'))
        ->assertSee(route('reports.stock-card.item', [$d->stock->id('YOGURT'), $d->beirut->id]));

    // The stock card link fills in the report's filters and opens its clean URL.
    $this->get(route('reports.stock-card.item', [$d->stock->id('YOGURT'), $d->beirut->id]))
        ->assertRedirect(route('reports.show', 'stock-card'));
    expect(session('list_state.reports.stock-card.properties.filters'))
        ->toMatchArray(['customer' => $d->acme->id, 'item' => $d->stock->id('YOGURT'), 'warehouse' => $d->beirut->id]);

    $this->get(route('stock.item', [$d->stock->id('PHONE'), $d->beirut->id]))->assertSee('SN-1')->assertSee('SN-2');

    // Newest first: the transfer out, the count, the damage, the issue, the receipt.
    $this->get(route('stock.item', [$d->stock->id('BEANS'), $d->beirut->id]))
        ->assertSeeInOrder([$d->transferInTransit->doc_no, '-12 Piece', '28 Piece', '-2 Piece', '40 Piece', '-6 Piece', '42 Piece', '-12 Piece', '48 Piece', '+60 Piece', '60 Piece'])
        ->assertSee(route('transfer.show', $d->transferInTransit->doc_no), false);
});

it('does not open an item of another warehouse or customer', function () {
    $d = $this->data;

    $this->actingAs($d->beirutManager)->get(route('stock.item', [$d->rice->id, $d->tripoli->id]))->assertNotFound();
    $this->actingAs($d->acmePortal)->get(route('stock.item', [$d->rice->id, $d->beirut->id]))->assertNotFound();
});

// Stock card

it('gives the stock card of one item: opening, movements with running balance, closing', function () {
    $d = $this->data;
    $filters = ['customer' => $d->acme->id, 'item' => $d->stock->id('BEANS'), 'warehouse' => $d->beirut->id];
    $card = onScreen($d->admin, $d->beirut, 'stock-card', $filters);

    expect($card['total'])->toBe(5)
        ->and(array_column(array_column($card['rows'], 'balance'), 'value'))->toBe([60.0, 48.0, 42.0, 40.0, 28.0])
        ->and(array_column($card['summary'], 'value'))->toBe(['0 Piece', '5 Box', '2 Box + 8 Piece', '2 Box + 4 Piece'])
        ->and(array_column(exported($d->admin, $d->beirut, 'stock-card', $filters), 'balance'))->toEqual([60, 48, 42, 40, 28]);

    // A later period opens with what was there before it.
    $later = onScreen($d->admin, $d->beirut, 'stock-card', $filters + ['from' => now()->addDay()->toDateString(), 'to' => now()->addDays(2)->toDateString()]);
    expect($later['total'])->toBe(0)
        ->and($later['summary'][0]['value'])->toBe('2 Box + 4 Piece');
});

it('asks for an item first, and never shows another customer’s item', function () {
    $d = $this->data;

    expect(onScreen($d->admin, $d->beirut, 'stock-card')['missing'])->toBe(__('reports.stock_card.choose_item'))
        ->and(onScreen($d->acmePortal, $d->beirut, 'stock-card', ['customer' => $d->cedar->id, 'item' => $d->rice->id, 'warehouse' => $d->beirut->id])['missing'])->not->toBeNull();

    // Nothing to export until an item is chosen.
    $page = reportPage($d->admin, $d->beirut, 'stock-card');
    expect($page->viewData('exportable'))->toBeFalse()
        ->and(data_get($page->call('export', 'xlsx')->effects, 'download'))->toBeNull();
});

// Expiry

it('lists expired and soon-expiring stock, nearest first', function () {
    $d = $this->data;
    $both = onScreen($d->admin, $d->beirut, 'expiry');

    expect(column($both['rows'], 'expiry_date'))->toBe([Format::date(now()->subDays(2)), Format::date(now()->addDays(10))])
        ->and(array_column(array_column($both['rows'], 'qty'), 'value'))->toBe([2.0, 12.0])
        // Whole calendar days in the warehouse's time zone: expired 2 days ago is -2, not -1.
        ->and(array_column(array_column($both['rows'], 'days_left'), 'value'))->toBe([-2, 10])
        ->and(array_column(array_column($both['rows'], 'status'), 'tone'))->toBe(['rejected', 'pending'])
        ->and(column(onScreen($d->admin, $d->beirut, 'expiry', ['status' => 'expired'])['rows'], 'expiry_date'))->toBe([Format::date(now()->subDays(2))])
        ->and(column(onScreen($d->admin, $d->beirut, 'expiry', ['status' => 'expiring'])['rows'], 'expiry_date'))->toBe([Format::date(now()->addDays(10))])
        ->and(onScreen($d->admin, $d->beirut, 'expiry', ['days' => '200'])['total'])->toBe(3)
        ->and(onScreen($d->auditorTripoli, $d->tripoli, 'expiry', ['warehouse' => 'all'])['total'])->toBe(0);
});

// Damage

it('reports damage by period, reason and warehouse', function () {
    $d = $this->data;
    $here = onScreen($d->admin, $d->beirut, 'damage');

    expect($here['total'])->toBe(2)
        ->and(array_column(array_column($here['rows'], 'qty'), 'value'))->toEqualCanonicalizing([6.0, 4.0])
        ->and($here['summary'][0]['value'])->toBe('2')
        ->and(column(onScreen($d->admin, $d->beirut, 'damage', ['reason' => 'EXPIRED'])['rows'], 'item'))->toBe(['Yogurt'])
        ->and(onScreen($d->admin, $d->beirut, 'damage', ['warehouse' => 'all'])['total'])->toBe(3)
        ->and(onScreen($d->admin, $d->beirut, 'damage', ['from' => now()->addDay()->toDateString(), 'to' => now()->addDays(5)->toDateString()])['total'])->toBe(0);
});

// Adjustments

it('reports adjustments with gains and losses apart', function () {
    $d = $this->data;
    $here = onScreen($d->admin, $d->beirut, 'adjustments');

    expect($here['total'])->toBe(2)
        ->and(array_column($here['summary'], 'value'))->toBe(['1 line', '1 line']);

    $losses = onScreen($d->admin, $d->beirut, 'adjustments', ['direction' => 'OUT'])['rows'];
    expect($losses)->toHaveCount(1)
        ->and($losses[0]['system']['value'])->toBe(42.0)
        ->and($losses[0]['counted']['value'])->toBe(40.0)
        ->and($losses[0]['difference']['text'])->toBe('-2 Piece')
        ->and($losses[0]['reason'])->toBe(__('transactions.reasons.LOSS_THEFT'));

    $gains = onScreen($d->admin, $d->beirut, 'adjustments', ['direction' => 'IN'])['rows'];
    expect($gains[0]['difference']['text'])->toBe('+2 Piece')
        ->and($gains[0]['customer'])->toBe('Cedar Trading');
});

// Transfers

it('lists stock in transit for the source and the destination', function () {
    $d = $this->data;
    $admin = onScreen($d->admin, $d->beirut, 'transfers-in-transit', ['warehouse' => 'all']);

    expect($admin['total'])->toBe(1)
        ->and($admin['rows'][0]['qty']['value'])->toBe(12.0)
        ->and($admin['rows'][0]['reference']['text'])->toBe($d->transferInTransit->doc_no)
        ->and(onScreen($d->auditorTripoli, $d->tripoli, 'transfers-in-transit', ['direction' => 'incoming'])['total'])->toBe(1)
        ->and(onScreen($d->auditorTripoli, $d->tripoli, 'transfers-in-transit', ['direction' => 'outgoing'])['total'])->toBe(0)
        ->and(onScreen($d->beirutManager, $d->beirut, 'transfers-in-transit', ['direction' => 'outgoing'])['total'])->toBe(1)
        ->and(onScreen($d->admin, $d->beirut, 'transfers-in-transit', ['customer' => $d->cedar->id])['total'])->toBe(0);
});

it('lists transfer shortages with the reason', function () {
    $d = $this->data;
    $rows = onScreen($d->auditorTripoli, $d->tripoli, 'transfer-shortages')['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['short']['value'])->toBe(1.0)
        ->and($rows[0]['reason'])->toBe(__('transfers.reasons.MISSING'))
        ->and(onScreen($d->acmePortal, $d->beirut, 'transfer-shortages', ['warehouse' => 'all'])['total'])->toBe(0)
        ->and(onScreen($d->admin, $d->beirut, 'transfer-shortages', ['reason' => 'COUNTING_ERROR'])['total'])->toBe(0);
});

// Audit log

it('shows the audit log only with audit.view, limited to the user’s documents', function () {
    $d = $this->data;

    $this->actingAs($d->beirutManager)->get(route('reports.show', 'audit-log'))->assertForbidden();

    $tripoliDocuments = DB::table('stock_transaction')
        ->where('warehouse_id', $d->tripoli->id)->orWhere('to_warehouse_id', $d->tripoli->id)->pluck('id');
    $expected = DB::table('audit_log')
        ->where(fn ($query) => $query->where('entity', '<>', 'stock_transaction')->orWhereIn('entity_id', $tripoliDocuments))
        ->count();

    $log = onScreen($d->auditorTripoli, $d->tripoli, 'audit-log');
    expect($log['total'])->toBe($expected)
        ->and(exported($d->auditorTripoli, $d->tripoli, 'audit-log'))->toHaveCount($expected)
        ->and(onScreen($d->admin, $d->beirut, 'audit-log')['total'])->toBe(DB::table('audit_log')->count());

    // Who, what, when, before → after.
    $receipt = collect(onScreen($d->auditorTripoli, $d->tripoli, 'audit-log', ['action' => 'RECEIVE'])['rows'])->sole();
    expect($receipt['who'])->toBe($d->tripoliOperator->full_name)
        ->and($receipt['record']['text'])->toContain($d->shortTransfer->doc_no)
        // In words, not codes: "Status: In transit → Completed".
        ->and($receipt['changes'])->toContain('Status: In transit → Completed')
        ->and($receipt['changes'])->not->toContain('DISPATCHED');
});

// Exports match the screen

it('exports exactly the rows the screen counts, in Excel and PDF', function (string $report, array $filters) {
    $d = $this->data;
    $filters = array_map(fn ($value) => match ($value) {
        'ACME' => $d->acme->id, 'BEANS' => $d->stock->id('BEANS'), 'BEIRUT' => $d->beirut->id, default => $value,
    }, $filters);

    foreach ([[$d->admin, $d->beirut], [$d->beirutManager, $d->beirut], [$d->acmePortal, $d->beirut]] as [$user, $warehouse]) {
        if (! $user->hasPermission(Reports::get($report)->permission())) {
            continue;
        }

        $screen = onScreen($user, $warehouse, $report, $filters);
        expect(exported($user, $warehouse, $report, $filters))->toHaveCount($screen['total']);

        // The PDF has every row too (its first cell; long cells wrap onto several lines).
        $pdf = preg_replace('/\s+/u', ' ', exportedPdf($user, $warehouse, $report, $filters));
        foreach ($screen['rows'] as $row) {
            $first = reset($row);
            expect($pdf)->toContain(preg_replace('/\s+/u', ' ', is_array($first) ? $first['text'] : $first));
        }
    }
})->with([
    'stock' => ['stock-on-hand', ['warehouse' => 'all']],
    'stock card' => ['stock-card', ['customer' => 'ACME', 'item' => 'BEANS', 'warehouse' => 'BEIRUT']],
    'expiry' => ['expiry', ['days' => '200']],
    'damage' => ['damage', ['warehouse' => 'all']],
    'adjustments' => ['adjustments', ['warehouse' => 'all']],
    'in transit' => ['transfers-in-transit', ['warehouse' => 'all']],
    'shortages' => ['transfer-shortages', ['warehouse' => 'all']],
    'audit log' => ['audit-log', []],
]);

// Arabic exports

it('exports Arabic right to left, with Arabic letters joined in the PDF', function () {
    $d = $this->data;
    $d->admin->update(['preferred_lang' => 'ar']);

    app()->setLocale('ar');
    $download = Exports::fromDownload(reportPage($d->admin->fresh(), $d->beirut, 'stock-on-hand')->call('export', 'xlsx'));
    [$header, , $rtl] = Exports::xlsxContent($download['content']);
    expect($rtl)->toBeTrue()
        ->and($header[0])->toBe(__('reports.columns.item', [], 'ar'));

    $text = exportedPdf($d->admin, $d->beirut, 'stock-on-hand');

    // "المخزون" (the title) as drawn: right to left, ل initial, م and خ medial, ز final.
    expect($text)->toContain("\u{0646}\u{0648}\u{FEB0}\u{FEA8}\u{FEE4}\u{FEDF}\u{0627}")
        // and never as separate letters in reading order.
        ->not->toContain('المخزون')
        // Codes stay as they are.
        ->toContain('BEANS');
});

it('makes PDFs light whatever the user’s theme', function () {
    $d = $this->data;
    $d->admin->update(['preferred_theme' => 'dark']);

    $html = view('exports.report-pdf', [
        'report' => Reports::get('damage'), 'columns' => Reports::get('damage')->columns(), 'rows' => collect(),
        'filters' => [], 'summary' => [], 'rtl' => false, 'generatedAt' => now(), 'by' => 'x',
    ])->render();

    expect($html)->toContain('background: #ffffff')->not->toContain('class="dark"');
});

// Reports page, sidebar and home

it('lists the reports the user may open', function () {
    $d = $this->data;

    $this->actingAs($d->beirutManager)->get(route('reports'))
        ->assertOk()
        ->assertSee(__('reports.stock_card.title'))
        ->assertDontSee(__('reports.audit_log.title'));
    $this->actingAs($d->auditorTripoli)->get(route('reports'))->assertSee(__('reports.audit_log.title'));
    $this->actingAs($d->tripoliOperator)->get(route('reports'))->assertForbidden();
    $this->actingAs($d->tripoliOperator)->get(route('stock'))->assertOk()->assertDontSee('href="'.route('reports').'"', false);
});

it('opens home with large links, approvals waiting and transfers arriving, and no charts', function () {
    $d = $this->data;
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 1]);
    $d->stock->post('DAMAGE', [$d->stock->line('BEANS', 2)], $d->stock->requester(), reason: 'HANDLING');

    $this->actingAs($d->beirutManager)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('href="'.route('damage.create').'"', false)
        ->assertSee(__('transactions.types.damage.to_approve'))
        // Something waiting stands out; nothing waiting is muted.
        ->assertSeeInOrder(['data-waiting="idle"', __('transactions.types.adjustment.to_approve'), 'data-waiting="active"', __('transactions.types.damage.to_approve')], false)
        ->assertDontSee('no approval needed')
        ->assertDontSee('<canvas', false);

    $this->actingAs($d->tripoliOperator)
        ->get(route('home'))
        ->assertSee(__('home.arriving'))
        ->assertSee('data-waiting="active"', false)
        ->assertSee(route('transfer.incoming'), false)
        ->assertDontSee(__('transactions.types.damage.to_approve'))
        ->assertDontSee('href="'.route('opening-balance.create').'"', false);
});

it('shows the Stock page, item details and reports in Arabic, right to left', function () {
    $d = $this->data;
    $d->admin->update(['preferred_lang' => 'ar']);
    $this->actingAs($d->admin);

    foreach ([
        route('stock') => 'reports.stock_on_hand.title',
        route('stock.item', [$d->stock->id('BEANS'), $d->beirut->id]) => 'reports.item.movements',
        route('reports') => 'reports.title',
        route('reports.show', 'damage') => 'reports.damage.title',
        route('home') => 'home.start',
    ] as $url => $key) {
        $this->get($url)
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__($key, ['count' => '20'], 'ar'));
    }

    // Codes stay left to right.
    $this->get(route('stock'))->assertSee('<span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">BEANS</span>', false);
});

it('filters every report by item name or code', function (string $report, array $filters, int $all, int $rice) {
    $d = $this->data;

    expect(onScreen($d->admin, $d->beirut, $report, $filters)['total'])->toBe($all)
        ->and(onScreen($d->admin, $d->beirut, $report, $filters + ['search' => 'rice'])['total'])->toBe($rice)
        ->and(exported($d->admin, $d->beirut, $report, $filters + ['search' => 'RICE']))->toHaveCount($rice);
})->with([
    'expiry' => ['expiry', ['days' => '200'], 3, 0],
    'damage' => ['damage', ['warehouse' => 'all'], 3, 1],
    'adjustments' => ['adjustments', [], 2, 1],
    'in transit' => ['transfers-in-transit', ['warehouse' => 'all'], 1, 0],
    'shortages' => ['transfer-shortages', ['warehouse' => 'all'], 1, 1],
]);

it('shows who approved a damage that needed approval', function () {
    $d = $this->data;
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 1]);
    $pending = $d->stock->post('DAMAGE', [$d->stock->line('BEANS', 3)], $d->stock->requester(), reason: 'TRANSPORT');
    app(PostingService::class)->approve($pending, $d->beirutManager);

    $rows = onScreen($d->admin, $d->beirut, 'damage', ['reason' => 'TRANSPORT'])['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['approved_by'])->toBe($d->beirutManager->full_name)
        ->and(column(onScreen($d->admin, $d->beirut, 'damage', ['reason' => 'HANDLING'])['rows'], 'approved_by'))->toBe(['']);
});
