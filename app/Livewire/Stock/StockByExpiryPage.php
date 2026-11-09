<?php

namespace App\Livewire\Stock;

use App\Livewire\Reports\ReportPage;
use App\Reports\StockByExpiryReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;

/**
 * Stock by expiry (report.stock_by_expiry.view, sidebar under Stock): the Stock
 * page split per lot (expiry date + batch) — the StockByExpiryReport with the
 * Stock page's layout. A row opens the item's stock in that warehouse.
 */
#[Layout('components.layouts.app')]
class StockByExpiryPage extends ReportPage
{
    public function mount(string $report = ''): void
    {
        parent::mount((new StockByExpiryReport)->key());
    }

    public function render(): View
    {
        $view = parent::render();
        $rows = $view->getData()['rows'];

        // The footer adds up every row the filters match, not only this page.
        return $view->with('totals', $this->rowsLoaded && ! $rows->isEmpty()
            ? (new StockByExpiryReport)->totals($this->definition()->normalize($this->filters, $this->user()), $this->user())
            : null);
    }

    protected function listStateKey(): string
    {
        return 'stock-by-expiry';
    }

    protected function view(): string
    {
        return 'livewire.stock.by-expiry';
    }
}
