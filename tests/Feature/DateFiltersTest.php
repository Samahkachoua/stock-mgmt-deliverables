<?php

use App\Livewire\Reports\ReportPage;
use App\Livewire\Stock\ItemMovements;
use App\Livewire\Transactions\TransactionList;
use App\Models\StockTransaction;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Exports;
use Tests\Support\Stock;

/*
 * "Date from" / "Date to" on lists and reports (CLAUDE.md "List state"): empty
 * by default, both days included, days as they are in the warehouse (Asia/Beirut).
 */

beforeEach(function () {
    $this->stock = Stock::create();
});

/**
 * Posts a document of $type dated $date (its own date, the Date column).
 */
function datedDocument(Stock $stock, string $type, string $date, float $qty, ?string $reason = null): StockTransaction
{
    return app(PostingService::class)->post(
        new Document($type, $stock->warehouse->id, $stock->customer->id, CarbonImmutable::parse($date), null, ['r1' => new DocumentLine($stock->id('BEANS'), 'PC', $qty)], reason: $reason),
        $stock->user,
    );
}

function dateList(Stock $stock, string $type = 'IN'): Testable
{
    return Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => $type]);
}

/**
 * @param  array<string, string>  $filters
 */
function datedReport(Stock $stock, string $report, array $filters = []): Testable
{
    session()->forget('list_state');
    $page = Livewire::actingAs($stock->user)->test(ReportPage::class, ['report' => $report]);

    return $filters === [] ? $page : $page->set('filters', $filters + $page->get('filters'));
}

/**
 * Queries run by $action that read documents or movements.
 *
 * @return list<string>
 */
function rowQueries(Closure $action): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $action();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, 'stock_transaction') || str_contains($sql, 'stock_movement') || str_contains($sql, 'audit_log'))
        ->values()->all();
}

it('reads a day strictly, and knows when the dates are the wrong way round', function () {
    expect(DateRange::day('2026-10-13'))->toBe('2026-10-13')
        ->and(DateRange::day('2026-02-31'))->toBe('')
        ->and(DateRange::day('13/10/2026'))->toBe('')
        ->and(DateRange::day(null))->toBe('')
        ->and((new DateRange('2026-10-14', '2026-10-13'))->invalid())->toBeTrue()
        ->and((new DateRange('2026-10-13', '2026-10-13'))->invalid())->toBeFalse()
        ->and((new DateRange('2026-10-14', ''))->invalid())->toBeFalse()
        ->and((new DateRange)->isEmpty())->toBeTrue();
});

it('lists every document without dates, and filters by the document date with both days included', function () {
    $old = datedDocument($this->stock, 'IN', '2026-09-01', 10);
    $middle = datedDocument($this->stock, 'IN', '2026-09-15', 10);
    $new = datedDocument($this->stock, 'IN', '2026-09-30', 10);

    $list = dateList($this->stock);
    expect($list->get('dateFrom'))->toBe('')
        ->and($list->get('dateTo'))->toBe('')
        ->and($list->viewData('rows')->total())->toBe(3);

    // Only "Date from": from that day onwards.
    $list->set('dateFrom', '2026-09-15')->assertSee($middle->doc_no)->assertSee($new->doc_no)->assertDontSee($old->doc_no);
    // Both: between them, both days included.
    $list->set('dateTo', '2026-09-15')->assertSee($middle->doc_no)->assertDontSee($new->doc_no)->assertDontSee($old->doc_no);
    // Only "Date to": up to and including that day.
    $list->set('dateFrom', '')->assertSee($old->doc_no)->assertSee($middle->doc_no)->assertDontSee($new->doc_no);

    // It is the document's own date, not when it was entered (all three were entered today).
    $list->set('dateFrom', now()->toDateString())->set('dateTo', '');
    expect($list->viewData('rows')->total())->toBe(0);
});

it('shows an error and runs no query when Date from is after Date to', function () {
    datedDocument($this->stock, 'IN', '2026-09-15', 10);
    $list = dateList($this->stock)->set('dateFrom', '2026-09-20');

    $queries = rowQueries(fn () => $list->set('dateTo', '2026-09-10'));

    expect($queries)->toBe([])
        ->and($list->viewData('rows')->total())->toBe(0)
        ->and($list->viewData('dateError'))->toBeTrue()
        ->and($list->html())->toContain('aria-invalid="true"')->toContain('border-danger')
        // Said on the page itself, next to the dates and in place of "Nothing matches": not only in a toast.
        ->toContain('data-date-error')->toContain(e(__('feedback.date_order_hint')))
        ->not->toContain(e(__('transactions.list.no_matches_title')));
    $list->assertDispatched(Notify::EVENT, fn (string $event, array $params) => $params['toast']['type'] === 'error'
        && $params['toast']['message'] === __('feedback.date_order'));

    // Fixed: the rows are back.
    $list->set('dateTo', '2026-09-20');
    expect($list->viewData('rows')->total())->toBe(0)
        ->and($list->viewData('dateError'))->toBeFalse();
    $list->set('dateFrom', '2026-09-15');
    expect($list->viewData('rows')->total())->toBe(1);
});

it('keeps the dates in the session, not the URL, and Clear filters empties them', function () {
    datedDocument($this->stock, 'IN', '2026-09-01', 10);
    datedDocument($this->stock, 'IN', '2026-09-15', 10);

    dateList($this->stock)->set('dateFrom', '2026-09-10')->set('dateTo', '2026-09-20');

    // Back from a details page: a new component, same session.
    $back = dateList($this->stock);
    expect($back->get('dateFrom'))->toBe('2026-09-10')
        ->and($back->get('dateTo'))->toBe('2026-09-20')
        ->and($back->viewData('rows')->total())->toBe(1)
        ->and(data_get($back->effects, 'url'))->toBeNull();

    $cleared = $back->call('resetFilters');
    expect($cleared->get('dateFrom'))->toBe('')
        ->and($cleared->get('dateTo'))->toBe('')
        ->and($cleared->viewData('rows')->total())->toBe(2)
        ->and(dateList($this->stock)->get('dateFrom'))->toBe('');
});

it('shows the two date fields, translated, with nothing chosen', function () {
    $this->actingAs($this->stock->user)->get(route('receive'))
        ->assertOk()
        ->assertSeeInOrder([__('transactions.filters.date_from'), __('transactions.filters.date_to')])
        ->assertSee('wire:model.live="dateFrom"', false)
        ->assertSee('wire:model.live="dateTo"', false);

    // Arabic: same order in the page, so "from" is on the right.
    $this->stock->user->update(['preferred_lang' => 'ar']);
    $this->actingAs($this->stock->user->fresh())->get(route('issue'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSeeInOrder([__('transactions.filters.date_from', [], 'ar'), __('transactions.filters.date_to', [], 'ar')]);

    expect(__('feedback.date_order', [], 'fr'))->not->toBe('feedback.date_order')
        ->and(__('transactions.filters.date_to', [], 'fr'))->not->toBe(__('transactions.filters.date_to', [], 'en'));
});

it('filters the damage report by document date, on screen and in the export', function () {
    datedDocument($this->stock, 'IN', '2026-08-01', 100);
    $old = datedDocument($this->stock, 'DAMAGE', '2026-09-01', 1, 'HANDLING');
    $middle = datedDocument($this->stock, 'DAMAGE', '2026-09-15', 2, 'HANDLING');
    $new = datedDocument($this->stock, 'DAMAGE', '2026-09-30', 3, 'HANDLING');

    $references = fn (Testable $page) => collect($page->viewData('rows')->items())->map(fn (array $row) => $row['cells']['reference']['text'])->sort()->values()->all();

    // No dates by default: everything.
    $page = datedReport($this->stock, 'damage');
    expect($page->get('filters.from'))->toBe('')
        ->and($page->get('filters.to'))->toBe('')
        ->and($references($page))->toBe(collect([$old->doc_no, $middle->doc_no, $new->doc_no])->sort()->values()->all());

    expect($references(datedReport($this->stock, 'damage', ['from' => '2026-09-15'])))->toEqualCanonicalizing([$middle->doc_no, $new->doc_no])
        ->and($references(datedReport($this->stock, 'damage', ['to' => '2026-09-15'])))->toEqualCanonicalizing([$old->doc_no, $middle->doc_no])
        ->and($references(datedReport($this->stock, 'damage', ['from' => '2026-09-15', 'to' => '2026-09-15'])))->toBe([$middle->doc_no]);

    // The export has the rows of the same dates.
    $download = Exports::fromDownload(datedReport($this->stock, 'damage', ['from' => '2026-09-15', 'to' => '2026-09-30'])->call('export', 'xlsx'));
    [, $rows] = Exports::xlsxContent($download['content']);
    $exported = collect($rows)->flatten()->filter(fn ($cell) => is_string($cell) && str_starts_with($cell, 'DMG-'))->values()->all();

    expect($exported)->toEqualCanonicalizing([$middle->doc_no, $new->doc_no]);
});

it('blocks a report and its export when Date from is after Date to', function () {
    datedDocument($this->stock, 'IN', '2026-08-01', 100);
    datedDocument($this->stock, 'DAMAGE', '2026-09-15', 2, 'HANDLING');
    $page = datedReport($this->stock, 'damage', ['from' => '2026-09-10']);

    $queries = rowQueries(fn () => $page->set('filters.to', '2026-09-01'));

    expect($queries)->toBe([])
        ->and($page->viewData('rows')->total())->toBe(0)
        ->and($page->viewData('missing'))->toBe(__('feedback.date_order'))
        ->and($page->viewData('exportable'))->toBeFalse()
        ->and($page->html())->toContain('aria-invalid="true"')->toContain('border-danger');
    $page->assertDispatched(Notify::EVENT, fn (string $event, array $params) => $params['toast']['message'] === __('feedback.date_order'));

    expect(data_get($page->call('export', 'xlsx')->effects, 'download'))->toBeNull();

    // Clear filters empties the dates again.
    $page->call('resetFilters');
    expect($page->get('filters.from'))->toBe('')
        ->and($page->get('filters.to'))->toBe('')
        ->and($page->viewData('rows')->total())->toBe(1);
});

it('starts the stock card at 0 without a Date from, and at the earlier balance with one', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 60)]);
    $this->stock->post('OUT', [$this->stock->line('BEANS', 12)]);
    $filters = ['customer' => $this->stock->customer->id, 'item' => $this->stock->id('BEANS'), 'warehouse' => $this->stock->warehouse->id];

    $card = datedReport($this->stock, 'stock-card', $filters);
    expect($card->viewData('rows')->total())->toBe(2)
        ->and($card->viewData('summary')[0]['value'])->toBe('0 Piece')
        ->and(collect($card->viewData('rows')->items())->map(fn (array $row) => $row['cells']['balance']['value'])->all())->toBe([60.0, 48.0]);

    // From tomorrow: nothing in the period, opening with what is there.
    $later = datedReport($this->stock, 'stock-card', $filters + ['from' => now('Asia/Beirut')->addDay()->toDateString()]);
    expect($later->viewData('rows')->total())->toBe(0)
        ->and($later->viewData('summary')[0]['value'])->toBe('4 Box');
});

it('dates, filters and orders the stock card by the documents’ own dates, not when they were entered', function () {
    $today = CarbonImmutable::now('Asia/Beirut')->startOfDay();
    // Entered in this order today, but dated 10 days ago, 3 days ago and 5 days ago.
    $first = datedDocument($this->stock, 'IN', $today->subDays(10)->toDateString(), 60);
    $last = datedDocument($this->stock, 'OUT', $today->subDays(3)->toDateString(), 12);
    $middle = datedDocument($this->stock, 'OUT', $today->subDays(5)->toDateString(), 6);
    $filters = ['customer' => $this->stock->customer->id, 'item' => $this->stock->id('BEANS'), 'warehouse' => $this->stock->warehouse->id];
    $cells = fn (Testable $page, string $cell) => collect($page->viewData('rows')->items())
        ->map(fn (array $row) => is_array($row['cells'][$cell]) ? ($row['cells'][$cell]['value'] ?? $row['cells'][$cell]['text']) : $row['cells'][$cell])->all();

    $card = datedReport($this->stock, 'stock-card', $filters);
    expect($cells($card, 'reference'))->toBe([$first->doc_no, $middle->doc_no, $last->doc_no])
        ->and($cells($card, 'date'))->toBe([Format::date($today->subDays(10)), Format::date($today->subDays(5)), Format::date($today->subDays(3))])
        ->and($cells($card, 'balance'))->toBe([60.0, 54.0, 42.0]);

    // Filtered by the document date: opening is what was there before it.
    $period = datedReport($this->stock, 'stock-card', $filters + ['from' => $today->subDays(6)->toDateString(), 'to' => $today->subDays(4)->toDateString()]);
    expect($cells($period, 'reference'))->toBe([$middle->doc_no])
        ->and($cells($period, 'balance'))->toBe([54.0])
        ->and($period->viewData('summary')[0]['value'])->toBe('5 Box')
        // Entered today, but nothing is dated today.
        ->and(datedReport($this->stock, 'stock-card', $filters + ['from' => $today->toDateString()])->viewData('rows')->total())->toBe(0);
});

it('counts a day as it is in Beirut: 23:30 on the Date to day is included', function () {
    $receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 60)]);
    $issue = $this->stock->post('OUT', [$this->stock->line('BEANS', 12)]);

    // 13 Oct 23:30 in Beirut (20:30 UTC), and 14 Oct 00:30 in Beirut (still 13 Oct in UTC).
    DB::table('stock_movement')->where('transaction_id', $receipt->id)->update(['created_at' => '2026-10-13 20:30:00+00']);
    DB::table('stock_movement')->where('transaction_id', $issue->id)->update(['created_at' => '2026-10-13 21:30:00+00']);
    DB::table('audit_log')->where('entity', 'stock_transaction')->where('entity_id', $receipt->id)->update(['created_at' => '2026-10-13 20:30:00+00']);
    DB::table('audit_log')->where('entity', 'stock_transaction')->where('entity_id', $issue->id)->update(['created_at' => '2026-10-13 21:30:00+00']);

    $filters = ['customer' => $this->stock->customer->id, 'item' => $this->stock->id('BEANS'), 'warehouse' => $this->stock->warehouse->id];
    $references = fn (Testable $page) => collect($page->viewData('rows')->items())->map(fn (array $row) => $row['cells']['reference']['text'])->all();

    // Stock card: by the documents' own dates (the recording time above doesn't matter).
    DB::table('stock_transaction')->where('id', $receipt->id)->update(['document_date' => '2026-10-13']);
    DB::table('stock_transaction')->where('id', $issue->id)->update(['document_date' => '2026-10-14']);

    expect($references(datedReport($this->stock, 'stock-card', $filters + ['to' => '2026-10-13'])))->toBe([$receipt->doc_no])
        ->and($references(datedReport($this->stock, 'stock-card', $filters + ['from' => '2026-10-14'])))->toBe([$issue->doc_no])
        ->and($references(datedReport($this->stock, 'stock-card', $filters + ['from' => '2026-10-13', 'to' => '2026-10-13'])))->toBe([$receipt->doc_no]);

    // Audit log (event time).
    $logged = fn (array $dates) => collect(datedReport($this->stock, 'audit-log', $dates + ['entity' => 'stock_transaction'])->viewData('rows')->items())
        ->map(fn (array $row) => collect($row['cells'])->map(fn ($cell) => is_array($cell) ? $cell['text'] : $cell)->implode(' '))
        ->implode(' | ');

    expect($logged(['to' => '2026-10-13']))->toContain($receipt->doc_no)->not->toContain($issue->doc_no)
        ->and($logged(['from' => '2026-10-14']))->toContain($issue->doc_no)->not->toContain($receipt->doc_no)
        ->and($logged([]))->toContain($receipt->doc_no)->toContain($issue->doc_no);

    // Item stock details: the same days, and the balance carries over.
    $movements = Livewire::actingAs($this->stock->user)
        ->test(ItemMovements::class, ['product' => $this->stock->id('BEANS'), 'warehouse' => $this->stock->warehouse->id]);
    $movements->assertSee($receipt->doc_no)->assertSee($issue->doc_no);
    $movements->set('dateTo', '2026-10-13')->assertSee($receipt->doc_no)->assertDontSee($issue->doc_no);
    $movements->set('dateTo', '')->set('dateFrom', '2026-10-14')->assertSee($issue->doc_no)->assertDontSee($receipt->doc_no)->assertSee('48 Piece');

    // Wrong way round: the error, no rows; Clear filters brings everything back.
    $movements->set('dateTo', '2026-10-01')->assertSee(__('feedback.date_order'))->assertDontSee($issue->doc_no)
        ->assertDispatched(Notify::EVENT);
    $movements->call('resetFilters')->assertSee($receipt->doc_no)->assertSee($issue->doc_no);
    expect($movements->get('dateFrom'))->toBe('');
});
