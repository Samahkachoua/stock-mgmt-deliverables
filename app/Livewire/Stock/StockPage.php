<?php

namespace App\Livewire\Stock;

use App\Livewire\Reports\ReportPage;
use App\Reports\StockOnHandReport;
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

    protected function listStateKey(): string
    {
        return 'stock';
    }

    protected function view(): string
    {
        return 'livewire.stock.page';
    }
}
