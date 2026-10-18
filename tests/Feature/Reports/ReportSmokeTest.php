<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Stock\StockPage;
use App\Reports\Reports;
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

it('opens every report and exports it in both formats', function (string $key) {
    $data = $this->data;
    $filters = $key === 'stock-card' ? ['customer' => $data->acme->id, 'item' => $data->stock->id('BEANS'), 'warehouse' => $data->beirut->id] : [];

    $this->actingAs($data->admin)->withSession([EnsureCurrentWarehouse::SESSION_KEY => $data->beirut->id]);

    $this->get($key === 'stock-on-hand' ? route('stock') : route('reports.show', $key))
        ->assertOk()
        ->assertSee(Reports::get($key)->title());

    $page = Livewire::test($key === 'stock-on-hand' ? StockPage::class : ReportPage::class, ['report' => $key]);
    if ($filters !== []) {
        $page->set('filters', $filters);
    }

    $xlsx = Exports::fromDownload($page->call('export', 'xlsx'));
    [$header] = Exports::xlsxContent($xlsx['content']);
    expect($xlsx['name'])->toEndWith('.xlsx')
        ->and($header)->toHaveCount(count(Reports::get($key)->columns()));

    $pdf = Exports::fromDownload($page->call('export', 'pdf'));
    expect($pdf['name'])->toEndWith('.pdf')
        ->and(Exports::pdfText($pdf['content']))->toContain(Reports::get($key)->title());
})->with(array_keys(Reports::all()));
