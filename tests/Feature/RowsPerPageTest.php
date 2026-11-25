<?php

/*
 * "Rows per page" under every list (App\Livewire\Concerns\WithPerPage and
 * x-data-table.pagination): remembered per list in the session, back to page 1
 * on a change, only the offered sizes, exports still take every row.
 */

use App\Livewire\Drafts\DraftList;
use App\Livewire\Imports\ImportList;
use App\Livewire\Items\ItemList;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Settings\Units;
use App\Livewire\Stock\StockPage;
use App\Livewire\Transactions\TransactionList;
use App\Support\ListState;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Exports;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();
    $this->stock->post('IN', [$this->stock->line('BEANS', 100)]);

    // 13 Orders: two pages of 8.
    $this->orders = collect(range(1, 13))->map(fn () => $this->stock->order([$this->stock->line('BEANS', 1)]));
});

function ordersList(): Testable
{
    return Livewire::actingAs(test()->stock->user)->test(DraftList::class, ['type' => 'OUT'])->call('loadRows');
}

it('shows 8 rows by default and as many as chosen', function () {
    $list = ordersList();

    expect($list->viewData('rows')->count())->toBe(8)
        ->and($list->get('perPage'))->toBe(8);
    $list->assertSeeText('Showing 1 to 8 of 13')->assertSeeHtml('data-per-page');

    $list->set('perPage', 25);

    expect($list->viewData('rows')->count())->toBe(13)
        ->and($list->viewData('rows')->hasPages())->toBeFalse();
    $list->assertSeeText('Showing 1 to 13 of 13');
});

it('goes back to page 1 when the size changes', function () {
    $list = ordersList()->call('nextPage');
    expect($list->viewData('rows')->currentPage())->toBe(2);

    $list->set('perPage', 10);

    expect($list->viewData('rows')->currentPage())->toBe(1)
        ->and($list->viewData('rows')->count())->toBe(10);
});

it('falls back to the default for a size it does not offer', function (int $size) {
    $list = ordersList()->set('perPage', $size);

    expect($list->get('perPage'))->toBe(8)
        ->and($list->viewData('rows')->count())->toBe(8);
})->with([7, 9999, 0, -5]);

it('offers the configured sizes plus the list’s own default', function () {
    expect(ordersList()->instance()->perPageOptions())->toBe([8, 10, 25, 50, 100, 500])
        ->and(Livewire::actingAs($this->stock->user)->test(ReportPage::class, ['report' => 'stock-on-hand'])->instance()->perPageOptions())
        ->toBe([8, 10, 20, 25, 50, 100, 500]);
});

it('turns a page past the end into the last page', function () {
    $list = ordersList()->call('gotoPage', 9);

    expect($list->viewData('rows')->currentPage())->toBe(2)
        ->and($list->viewData('rows')->count())->toBe(5);
});

it('keeps the search with a new size', function () {
    $number = $this->orders->first()->doc_no;

    $list = ordersList()->set('search', $number)->set('perPage', 25);

    expect($list->get('search'))->toBe($number)
        ->and(collect($list->viewData('rows')->items())->pluck('reference')->all())->toBe([$number]);
});

it('remembers the size per list, also after Clear filters', function () {
    ordersList()->set('perPage', 25)->set('search', 'ORD')->call('resetFilters');

    expect(ListState::perPage('orders'))->toBe(25)
        ->and(ordersList()->get('perPage'))->toBe(25)
        // Another list keeps its own default.
        ->and(Livewire::actingAs($this->stock->user)->test(DraftList::class, ['type' => 'IN'])->get('perPage'))->toBe(8);
});

it('does not carry a size over to another user in the same session', function () {
    ordersList()->set('perPage', 50);

    $other = userWithRole('ADMIN', $this->stock->warehouse);

    expect(Livewire::actingAs($other)->test(DraftList::class, ['type' => 'OUT'])->get('perPage'))->toBe(8);
});

it('sits at the start of the toolbar, before the search, not under the table', function () {
    ordersList()->assertSeeHtmlInOrder(['data-list-toolbar', 'data-per-page', 'id="filter-search"', 'data-clear-filters', 'data-list']);

    Livewire::actingAs($this->stock->user)->test(ReportPage::class, ['report' => 'stock-card'])
        ->assertSeeHtmlInOrder(['data-list-toolbar', 'data-per-page', 'report-filter-search', 'data-clear-filters']);
});

it('still exports every filtered row, whatever the page size', function () {
    $list = ordersList()->set('perPage', 10)->call('nextPage');

    [, $rows] = Exports::xlsxContent(Exports::fromDownload($list->call('export', 'xlsx'))['content']);

    expect($rows)->toHaveCount(13)
        ->and($list->viewData('rows')->currentPage())->toBe(2)
        ->and($list->get('perPage'))->toBe(10);
});

it('works on every kind of list', function (string $component, array $params, string $rows) {
    $list = Livewire::actingAs(userWithRole('ADMIN', $this->stock->warehouse))->test($component, $params)->call('loadRows');
    $default = $list->viewData($rows)->perPage();

    $list->set('perPage', 50);

    expect($default)->toBe($component::PER_PAGE)
        ->and($list->viewData($rows)->perPage())->toBe(50);
})->with([
    'delivery notes' => [TransactionList::class, ['type' => 'OUT'], 'rows'],
    'orders' => [DraftList::class, ['type' => 'OUT'], 'rows'],
    'items' => [ItemList::class, [], 'items'],
    'imports' => [ImportList::class, [], 'jobs'],
    'settings' => [Units::class, [], 'rows'],
    'report' => [ReportPage::class, ['report' => 'stock-card'], 'rows'],
    'stock' => [StockPage::class, ['report' => 'stock-on-hand'], 'rows'],
]);
