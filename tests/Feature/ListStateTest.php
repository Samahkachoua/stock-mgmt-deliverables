<?php

use App\Livewire\Imports\ImportList;
use App\Livewire\Items\ItemList;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Settings\Customers;
use App\Livewire\Transactions\TransactionList;
use App\Models\StockTransaction;
use App\Reports\Reports;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;
use Tests\Support\Catalog;
use Tests\Support\Exports;
use Tests\Support\ReportData;
use Tests\Support\Stock;

/*
 * CLAUDE.md "List state": filters, sorting and page live in the session per
 * list, never in the URL; they survive a visit to a details page; "Clear
 * filters" resets them; exports use them.
 */

/**
 * Every Livewire component class of the app.
 *
 * @return list<class-string>
 */
function livewireComponents(): array
{
    $classes = [];
    $root = app_path('Livewire');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'App\\Livewire\\'.str_replace(['/', '\\'], '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Nothing about the list's state reaches the URL: no query-string effect and
 * no links carrying a page or filters.
 */
function expectCleanUrl(Testable $list): void
{
    expect(data_get($list->effects, 'url'))->toBeEmpty()
        ->and($list->html())->not->toContain('?page=')->not->toContain('?search=')->not->toContain('&page=');
}

/**
 * A receive list with $count receipts (the list shows 8 per page).
 */
function receipts(Stock $stock, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $stock->post('IN', [$stock->line('BEANS', 1)]);
    }
}

function receiveList(Stock $stock): Testable
{

    return Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'IN']);
}

it('has no #[Url] state, and every paginated list paginates without the URL', function () {
    $paginated = 0;

    foreach (livewireComponents() as $class) {
        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
        expect($source)->not->toContain('Livewire\Attributes\Url', $class);

        $traits = class_uses_recursive($class);
        if (in_array(WithPagination::class, $traits, true)) {
            $paginated++;
            expect($traits)->toContain(WithoutUrlPagination::class);
        }
    }

    expect($paginated)->toBeGreaterThanOrEqual(6);
});

it('never puts the filters or the page of a transaction list in the URL', function () {
    $stock = Stock::create();
    receipts($stock, 10);

    $list = receiveList($stock)->set('search', 'REC')->set('status', 'POSTED')->call('nextPage');

    expect($list->viewData('rows')->currentPage())->toBe(2)
        ->and($list->viewData('rows')->total())->toBe(10);
    expectCleanUrl($list);

    // The page itself: a plain URL, and links without parameters.
    expect(route('receive'))->not->toContain('?');
    $this->actingAs($stock->user)->get(route('receive'))->assertOk()->assertDontSee('?page=', false);
});

it('keeps the filters and page when the user opens a document and comes back', function () {
    $stock = Stock::create();
    receipts($stock, 10);
    $receipt = StockTransaction::withoutGlobalScopes()->where('transaction_type', 'IN')->orderBy('doc_no')->first();

    $today = now()->toDateString();
    receiveList($stock)->set('status', 'POSTED')->set('dateFrom', $today)->call('nextPage');

    // Details page, then back to the list (a new component, same session).
    $this->actingAs($stock->user)->get(route('receive.show', $receipt->doc_no))->assertOk();
    $back = receiveList($stock);

    expect($back->get('status'))->toBe('POSTED')
        ->and($back->get('dateFrom'))->toBe($today)
        ->and($back->get('dateTo'))->toBe('')
        ->and($back->viewData('rows')->currentPage())->toBe(2);
    expectCleanUrl($back);

    // A full page load of the clean URL starts from the same remembered state.
    $this->actingAs($stock->user)->get(route('receive'))->assertOk()->assertSee('"status":"POSTED"');
});

it('keeps each list’s state apart', function () {
    $stock = Stock::create();

    receiveList($stock)->set('search', 'only receipts');
    $issues = Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'OUT']);

    expect($issues->get('search'))->toBe('')
        ->and(receiveList($stock)->get('search'))->toBe('only receipts');

    // Per user: someone else signing in within the same session starts clean.
    $colleague = userWithRole('ADMIN', $stock->warehouse);
    expect(Livewire::actingAs($colleague)->test(TransactionList::class, ['type' => 'IN'])->get('search'))->toBe('');
});

it('clears the remembered filters with “Clear filters”', function () {
    $stock = Stock::create();
    receipts($stock, 10);

    receiveList($stock)->set('search', 'REC')->set('status', 'POSTED')->set('dateFrom', '2020-01-01')->set('dateTo', '2030-12-31')->call('nextPage');
    $cleared = receiveList($stock)->call('resetFilters');

    expect($cleared->get('search'))->toBe('')
        ->and($cleared->get('status'))->toBe('')
        ->and($cleared->get('dateFrom'))->toBe('')
        ->and($cleared->get('dateTo'))->toBe('')
        ->and($cleared->viewData('rows')->currentPage())->toBe(1)
        ->and($cleared->html())->toContain(__('transactions.filters.reset'));

    expect(receiveList($stock)->get('search'))->toBe('');
});

it('goes back to the last page when the remembered page no longer exists', function () {
    $stock = Stock::create();
    receipts($stock, 10);
    session(['list_state.receive' => ['properties' => [], 'page' => 99]]);

    expect(receiveList($stock)->viewData('rows')->currentPage())->toBe(2);
});

it('opens the incoming transfers from Home through a clean URL', function () {
    $stock = Stock::create();
    $this->actingAs($stock->user);

    $this->get(route('transfer.incoming'))->assertRedirect(route('transfer'));
    expect(Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'TRANSFER'])->get('direction'))->toBe('incoming');

    // Only dispatch/receive types have incoming documents.
    $this->get(route('receive.incoming'))->assertNotFound();
});

it('remembers item, settings and import lists without the URL', function () {
    $catalog = Catalog::create();
    $user = $catalog->user();

    $items = Livewire::actingAs($user)->test(ItemList::class)->set('customer', $catalog->acme->id)->call('nextPage');
    expectCleanUrl($items);
    $again = Livewire::actingAs($user)->test(ItemList::class);
    expect($again->get('customer'))->toBe($catalog->acme->id)
        ->and($again->viewData('items')->currentPage())->toBe(2);

    $customers = Livewire::actingAs($user)->test(Customers::class)->set('search', 'cedar');
    expectCleanUrl($customers);
    expect(Livewire::actingAs($user)->test(Customers::class)->get('search'))->toBe('cedar')
        ->and(Livewire::actingAs($user)->test(Customers::class)->call('resetFilters')->get('search'))->toBe('');

    expectCleanUrl(Livewire::actingAs($user)->test(ImportList::class));
});

it('remembers a report’s filters and exports exactly what they show', function () {
    $data = ReportData::create();

    $page = Livewire::actingAs($data->admin)->test(ReportPage::class, ['report' => 'damage'])
        ->set('filters.warehouse', 'all')
        ->set('filters.reason', 'EXPIRED');
    expectCleanUrl($page);
    $shown = $page->viewData('rows')->total();

    // Back on the report later: same filters, and the export holds those rows only.
    $later = Livewire::actingAs($data->admin)->test(ReportPage::class, ['report' => 'damage']);
    expect($later->get('filters.reason'))->toBe('EXPIRED')
        ->and($later->viewData('rows')->total())->toBe($shown);

    [, $rows] = Exports::xlsxContent(Exports::fromDownload($later->call('export', 'xlsx'))['content']);
    $reasons = array_column(array_map(fn (array $row) => array_combine(array_keys(Reports::get('damage')->columns()), $row), $rows), 'reason');

    expect($rows)->toHaveCount($shown)
        ->and(array_unique($reasons))->toBe([__('transactions.reasons.EXPIRED')]);

    // Cleared: every damage line again, in the export too.
    $all = $later->call('resetFilters');
    expect($all->get('filters.reason'))->toBe('');
    [, $everything] = Exports::xlsxContent(Exports::fromDownload($all->call('export', 'xlsx'))['content']);
    expect(count($everything))->toBe($all->viewData('rows')->total())
        ->and(count($everything))->toBeGreaterThanOrEqual($shown);
});
