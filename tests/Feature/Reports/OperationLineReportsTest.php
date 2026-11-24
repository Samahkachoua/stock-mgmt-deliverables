<?php

use App\Livewire\Reports\ReportPage;
use App\Models\CustomerBranch;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Reports;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Support\Drafts\DraftTypes;
use Carbon\CarbonImmutable;
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
 * An operation detail report as the user sees it with these filters: total
 * rows, cells of the first page, and the Excel export's data rows.
 *
 * @param  array<string, string>  $filters
 * @return array{total: int, rows: list<array<string, mixed>>, summary: list<array{label: string, value: string}>, excel: int}
 */
function operationLines(User $user, Warehouse $current, string $report, array $filters = []): array
{
    startIn($user, $current);
    session()->forget('list_state');

    $page = Livewire::actingAs($user)->test(ReportPage::class, ['report' => $report]);
    if ($filters !== []) {
        $page->set('filters', $filters);
    }

    $rows = $page->viewData('rows');
    [, $excel] = Exports::xlsxContent(Exports::fromDownload($page->call('export', 'xlsx'))['content']);

    return [
        'total' => $rows->total(),
        'rows' => collect($rows->items())->pluck('cells')->all(),
        'summary' => $page->viewData('summary'),
        'excel' => count($excel),
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function operationColumn(array $rows, string $key): array
{
    return array_map(fn (array $row) => is_array($row[$key]) ? $row[$key]['text'] : $row[$key], $rows);
}

it('lists every receipt line, filtered by warehouse, customer, status and item', function () {
    $d = $this->data;

    // Acme's receipt in Beirut (5 lines), Cedar's in Beirut and in Tripoli (1 each).
    $all = operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all']);
    expect($all['total'])->toBe(7)
        ->and($all['excel'])->toBe(7)
        ->and($all['summary'][0]['value'])->toBe('3');

    expect(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => $d->tripoli->id])['total'])->toBe(1)
        ->and(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all', 'customer' => $d->cedar->id])['total'])->toBe(2)
        ->and(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all', 'search' => 'rice'])['total'])->toBe(2)
        ->and(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all', 'status' => 'POSTED'])['total'])->toBe(7)
        ->and(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all', 'status' => 'CANCELLED'])['total'])->toBe(0);

    // Dates are the document date: nothing before today.
    expect(operationLines($d->admin, $d->beirut, 'receipt-lines', ['warehouse' => 'all', 'to' => now()->subDay()->toDateString()])['total'])->toBe(0);
});

it('never shows lines of a warehouse the user is not assigned to', function () {
    $d = $this->data;

    // The Beirut manager asking for "all": only Beirut's 6 lines.
    $beirut = operationLines($d->beirutManager, $d->beirut, 'receipt-lines', ['warehouse' => 'all']);
    expect($beirut['total'])->toBe(6)
        ->and(array_unique(operationColumn($beirut['rows'], 'warehouse')))->toBe([$d->beirut->name]);

    // Asking for Tripoli falls back to their own warehouse.
    expect(operationLines($d->beirutManager, $d->beirut, 'receipt-lines', ['warehouse' => $d->tripoli->id])['total'])->toBe(6);
});

it('shows delivery note lines with ordered and delivered quantities and their order', function () {
    $d = $this->data;
    $report = operationLines($d->admin, $d->beirut, 'delivery-note-lines');

    expect($report['total'])->toBe(1)
        ->and($report['rows'][0]['qty']['value'])->toBe(12.0)
        ->and($report['rows'][0])->not->toHaveKey('qty_base')
        ->and($report['rows'][0]['delivered']['value'])->toBe(12.0)
        ->and($report['rows'][0]['status']['text'])->toBe(__('transactions.status.delivered'))
        ->and($report['rows'][0]['order']['text'])->toStartWith('ORD-');

    // The Delivery note's own statuses: Delivered, never Pending approval.
    $statuses = collect(Reports::get('delivery-note-lines')->filters())->firstWhere('name', 'status')->options;
    expect($statuses)->toHaveKeys(['', 'CONFIRMED', 'DISPATCHED', 'POSTED', 'CANCELLED'])
        ->and($statuses)->not->toHaveKey('PENDING_APPROVAL');
});

it('shows order lines with the delivery note they became, and draft receipts by status', function () {
    $d = $this->data;
    $orders = operationLines($d->admin, $d->beirut, 'order-lines');

    expect($orders['total'])->toBe(1)
        ->and($orders['rows'][0]['status']['text'])->toBe(DraftTypes::statusLabel('POSTED'))
        ->and($orders['rows'][0]['created']['text'])->toStartWith('DN-')
        ->and(operationLines($d->admin, $d->beirut, 'order-lines', ['status' => 'DRAFT'])['total'])->toBe(0);

    // A draft receipt, not confirmed yet: in the report as Draft, with no receipt.
    $d->stock->order([$d->stock->line('BEANS', 1)]);
    $receipt = app(DraftService::class)->save(new DraftInput(
        'IN', $d->acme->id, CustomerBranch::where('customer_id', $d->acme->id)->where('code', 'MAIN')->value('id'), $d->beirut->id, CarbonImmutable::today(), null,
        ['r1' => new DraftLineInput($d->stock->id('BEANS'), 'PC', '3', null, [], null)],
    ), $d->admin);

    $drafts = operationLines($d->admin, $d->beirut, 'draft-receipt-lines', ['status' => 'DRAFT']);
    expect($drafts['total'])->toBe(1)
        ->and($drafts['rows'][0]['reference']['text'])->toBe($receipt->doc_no)
        ->and($drafts['rows'][0]['created']['text'])->toBe('')
        ->and(operationLines($d->admin, $d->beirut, 'order-lines', ['status' => 'DRAFT'])['total'])->toBe(1);
});

it('lists opening balance lines without a branch', function () {
    $d = $this->data;
    $d->stock->post('OPENING_BALANCE', [$d->stock->line('BEANS', 4)]);

    $report = operationLines($d->admin, $d->beirut, 'opening-balance-lines');

    expect($report['total'])->toBe(1)
        ->and($report['rows'][0])->not->toHaveKeys(['branch', 'status', 'qty_base'])
        ->and($report['rows'][0]['qty']['value'])->toBe(4.0)
        ->and(Reports::get('opening-balance-lines')->columns())->not->toHaveKey('branch');
});

it('opens each operation detail report only with its own permission', function (string $key) {
    $d = $this->data;
    $report = Reports::get($key);

    // The operator sees the lists but no reports.
    $this->actingAs($d->tripoliOperator)->get(route('reports.show', $key))->assertForbidden();
    $this->actingAs($d->beirutManager)->get(route('reports.show', $key))->assertOk()->assertSee($report->title());
    expect($d->beirutManager->hasPermission($report->permission()))->toBeTrue();
})->with(['order-lines', 'delivery-note-lines', 'draft-receipt-lines', 'receipt-lines', 'opening-balance-lines']);
