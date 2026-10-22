<?php

namespace App\Livewire\Stock;

use App\Http\Controllers\ItemStockController;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyTime;
use App\Support\DateRange;
use App\Support\ListState;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The movements block of the item stock details page: the newest movements of
 * one item in one warehouse with the balance after each, between "Date from"
 * and "Date to" (both empty by default; days in the warehouse's time zone).
 * The dates are kept in the session per item and warehouse (CLAUDE.md "List
 * state"), never in the URL.
 */
class ItemMovements extends Component
{
    #[Locked]
    public string $product = '';

    #[Locked]
    public string $warehouse = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(string $product, string $warehouse): void
    {
        $this->product = $product;
        $this->warehouse = $warehouse;

        $state = ListState::get($this->stateKey())['properties'] ?? [];
        $this->dateFrom = DateRange::day($state['dateFrom'] ?? '');
        $this->dateTo = DateRange::day($state['dateTo'] ?? '');
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['dateFrom', 'dateTo'], true)) {
            return;
        }

        $this->{$property} = DateRange::day($this->{$property});
        ListState::put($this->stateKey(), ['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo]);

        if ($this->dates()->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('dateFrom', 'dateTo');
        ListState::forget($this->stateKey());
    }

    public function render(): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWarehouse($this->warehouse), 404);

        // The product query applies the customer scope.
        $product = Product::query()->with(['baseUnit', 'units.unit'])->findOrFail($this->product);
        $warehouse = Warehouse::query()->findOrFail($this->warehouse);
        $dates = $this->dates();

        return view('livewire.stock.item-movements', [
            'movements' => $dates->invalid() ? collect() : $this->movements($warehouse, $dates),
            'warehouseName' => $warehouse->name,
            'base' => $product->converter()->base(),
            'limit' => ItemStockController::MOVEMENTS,
            'filtered' => ! $dates->isEmpty(),
            'dateError' => $dates->invalid(),
        ]);
    }

    /**
     * The newest movements of the chosen days, with the balance after each:
     * what was there before "Date from", plus every movement up to it.
     *
     * @return Collection<int, array{at: CarbonImmutable, type: string, reference: string, link: string|null, route: string|null, qty: float, balance: float}>
     */
    private function movements(Warehouse $warehouse, DateRange $dates): Collection
    {
        $start = $dates->start(CompanyTime::zone());
        $opening = $start === null ? 0.0 : (float) $this->history()
            ->where('stock_movement.created_at', '<', $start)
            ->selectRaw("coalesce(sum(case when direction = 'IN' then qty_base else -qty_base end), 0) as qty")
            ->value('qty');

        $rows = $dates->whereInstant($this->history(), 'stock_movement.created_at', CompanyTime::zone())
            ->join('stock_transaction as t', 't.id', '=', 'stock_movement.transaction_id')
            ->join('warehouse as wf', 'wf.id', '=', 't.warehouse_id')
            ->leftJoin('warehouse as wt', 'wt.id', '=', 't.to_warehouse_id')
            ->select(['stock_movement.direction', 'stock_movement.qty_base', 'stock_movement.created_at', 't.doc_no', 't.transaction_type', 'wf.name as from_name', 'wt.name as to_name'])
            ->selectRaw("? + sum(case when stock_movement.direction = 'IN' then stock_movement.qty_base else -stock_movement.qty_base end) over (order by stock_movement.seq) as balance", [$opening])
            ->orderByDesc('stock_movement.seq')
            ->limit(ItemStockController::MOVEMENTS)
            ->get();

        return collect($rows->all())->map(function (StockMovement $movement) {
            $type = TransactionTypes::all()[$movement->getAttribute('transaction_type')] ?? null;

            return [
                'at' => CarbonImmutable::parse($movement->getAttribute('created_at'))->setTimezone(CompanyTime::zone()),
                'type' => (string) $movement->getAttribute('transaction_type'),
                'reference' => (string) $movement->getAttribute('doc_no'),
                'link' => $type?->route('show', $movement->getAttribute('doc_no')),
                'route' => $movement->getAttribute('to_name') !== null
                    ? $movement->getAttribute('from_name').' → '.$movement->getAttribute('to_name')
                    : null,
                'qty' => $movement->direction === 'IN' ? (float) $movement->qty_base : -(float) $movement->qty_base,
                'balance' => (float) $movement->getAttribute('balance'),
            ];
        });
    }

    /**
     * The item's movements in the warehouse (scoped: the user's warehouses, their customer).
     *
     * @return Builder<StockMovement>
     */
    private function history(): Builder
    {
        return StockMovement::query()
            ->where('stock_movement.product_id', $this->product)
            ->where('stock_movement.warehouse_id', $this->warehouse);
    }

    private function dates(): DateRange
    {
        return new DateRange($this->dateFrom, $this->dateTo);
    }

    private function stateKey(): string
    {
        return 'stock.item.'.$this->product.'.'.$this->warehouse;
    }
}
