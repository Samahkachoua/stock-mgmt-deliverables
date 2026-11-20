<?php

/*
 * Every list's toolbar: Excel / PDF of every row its filters match, without the
 * columns the viewer hid (App\Livewire\Concerns\ExportsList, ExportTable).
 */

use App\Livewire\Reports\ReportPage;
use App\Livewire\Settings\Units;
use App\Livewire\Transactions\TransactionList;
use App\Reports\Export\ExportTable;
use App\Reports\Reports;
use Livewire\Livewire;
use Tests\Support\Exports;
use Tests\Support\Stock;

beforeEach(function () {
    $this->stock = Stock::create();

    // More receipts than one page holds (8), so the export has to take every page.
    foreach (range(1, 10) as $i) {
        $this->stock->post('IN', [$this->stock->line('BEANS', $i)]);
    }
});

it('exports every row of a list, not only the page shown', function () {
    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->call('nextPage');
    $total = $list->viewData('rows')->total();

    [$header, $rows] = Exports::xlsxContent(Exports::fromDownload($list->call('export', 'xlsx'))['content']);

    expect($rows)->toHaveCount($total)
        ->and($header[0])->toBe(__('transactions.list.reference'))
        ->and($header)->toContain(__('transactions.list.status'))
        // References as on screen, the newest first like the list.
        ->and($rows[0][0])->toStartWith('REC-')
        // The user stays on the page they were on.
        ->and($list->viewData('rows')->currentPage())->toBe(2);
});

it('leaves the columns the viewer hid out of the export', function () {
    $list = Livewire::actingAs($this->stock->user)->test(TransactionList::class, ['type' => 'IN'])
        ->call('loadRows')
        ->set('hiddenColumns', ['created_by', 'items']);

    [$header] = Exports::xlsxContent(Exports::fromDownload($list->call('export', 'xlsx'))['content']);

    expect($header)->not->toContain(__('transactions.list.created_by'))
        ->not->toContain(__('transactions.list.items'))
        ->toContain(__('transactions.list.reference'));
});

it('exports a settings list as PDF without its row actions', function () {
    $download = Exports::fromDownload(Livewire::actingAs($this->stock->user)->test(Units::class)->call('loadRows')->call('export', 'pdf'));
    $text = Exports::pdfText($download['content']);

    // PDF headings are printed in capitals.
    expect($download['name'])->toEndWith('.pdf')
        ->and(mb_strtoupper($text))->toContain(mb_strtoupper(__('settings.units.used_by')))
        ->and(mb_strtoupper($text))->not->toContain(mb_strtoupper(__('settings.units.delete')));
});

it('leaves hidden columns out of a report export too', function () {
    $page = Livewire::actingAs($this->stock->user)->test(ReportPage::class, ['report' => 'stock-on-hand'])
        ->set('hiddenColumns', ['code']);

    [$header] = Exports::xlsxContent(Exports::fromDownload($page->call('export', 'xlsx'))['content']);
    $columns = Reports::get('stock-on-hand')->columns();

    expect($header)->toContain(__($columns['item']['label']))
        ->not->toContain(__($columns['code']['label']));
});

it('reads a list table: headings, cells, no screen-reader text, no heading-less columns', function () {
    $html = <<<'HTML'
        <table data-list>
            <thead><tr><th data-col="ref">Reference</th><th data-col="qty" class="px-cell text-end">Qty</th><th data-col="delete"></th><th>open</th></tr></thead>
            <tbody>
                <tr><td data-col="ref"> REC-1 <span class="sr-only">Open REC-1</span></td><td data-col="qty">12   Piece</td><td data-col="delete"><button><span class="sr-only">Delete</span></button></td><td></td></tr>
                <tr aria-hidden="true"><td data-col="ref"></td></tr>
            </tbody>
        </table>
        HTML;

    $table = ExportTable::fromHtml($html, 'Receipts', 'receive');

    expect($table->columns)->toBe(['ref' => ['label' => 'Reference', 'align' => 'start'], 'qty' => ['label' => 'Qty', 'align' => 'end']])
        ->and($table->rows)->toBe([['ref' => 'REC-1', 'qty' => '12 Piece']]);
});
