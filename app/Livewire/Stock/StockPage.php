<?php

namespace App\Livewire\Stock;

use App\Livewire\Reports\ReportPage;
use App\Reports\StockOnHandReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;

/**
 * The Stock page (stock.view, docs/design/stitch-exports/stock-inventory-balance-list):
 * stock on hand per item, customer and warehouse — the StockOnHandReport with
 * its own layout. A row opens the item's stock in that warehouse.
 */
#[Layout('components.layouts.app')]
class StockPage extends ReportPage
{
    public function mount(string $report = ''): void
    {
        parent::mount((new StockOnHandReport)->key());
    }

    public function render(): View
    {
        $view = parent::render();
        $rows = $view->getData()['rows'];

        // The footer adds up every row the filters match, not only this page.
        return $view->with('totals', $this->rowsLoaded && ! $rows->isEmpty()
            ? (new StockOnHandReport)->totals($this->definition()->normalize($this->filters, $this->user()), $this->user())
            : null);
    }

    protected function listStateKey(): string
    {
        return 'stock';
    }

    protected function view(): string
    {
        return 'livewire.stock.page';
    }
}
