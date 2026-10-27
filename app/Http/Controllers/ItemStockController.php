<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\StockBalance;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Reports;
use App\Reports\StockOnHandReport;
use App\Services\Stock\Reservations;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ItemStockController extends Controller
{
    /** Movements shown on the page; the stock card report has them all. */
    public const MOVEMENTS = 20;

    /**
     * One item's stock in one warehouse (docs/design/stitch-exports/item-stock-details):
     * balance per expiry date and serials in stock; the last movements are the
     * App\Livewire\Stock\ItemMovements block of the page. Read-only; the product binding applies the customer
     * scope, and the warehouse must be one of the user's.
     */
    public function show(Request $request, Product $product, Warehouse $warehouse): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessWarehouse($warehouse->id), 404);
        // A service has no stock (no report links to it).
        abort_if($product->is_service, 404);

        $product->load(['baseUnit', 'units.unit', 'customer']);
        $converter = $product->converter();
        $today = CompanyTime::today();

        $expiries = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('qty_base', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST, batch_key ASC')
            ->get(['qty_base', 'expiry_date', 'batch_no'])
            ->map(fn ($row) => [
                'expiry' => $row->getAttribute('expiry_date') ? CarbonImmutable::parse($row->getAttribute('expiry_date')) : null,
                'batch' => $row->getAttribute('batch_no'),
                'qty' => (float) $row->qty_base,
            ]);

        $total = (float) $expiries->sum('qty');
        $nearest = $expiries->pluck('expiry')->filter()->first();

        $arriving = (float) StockTransaction::query()
            ->join('in_transit_balance as i', 'i.transaction_id', '=', 'stock_transaction.id')
            ->where('stock_transaction.status', 'DISPATCHED')
            ->where('stock_transaction.to_warehouse_id', $warehouse->id)
            ->where('i.product_id', $product->id)
            ->sum('i.qty_base');

        // Held for orders / delivery notes not delivered yet, and by which.
        $reserved = (float) StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sum('qty_reserved');
        $holders = collect(app(Reservations::class)->holders($warehouse->id, $product->customer_id, $product->id))
            ->map(fn (array $holder) => $holder + ['url' => $holder['draft']
                ? DraftTypes::get($holder['type'])->route('show', $holder['reference'])
                : TransactionTypes::get($holder['type'])->route('show', $holder['reference'])]);

        $serials = $product->track_serial
            ? SerialNumber::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->where('status', 'IN_STOCK')->orderBy('serial_no')->pluck('serial_no')
            : collect();

        return view('stock.item', [
            'product' => $product,
            'warehouse' => $warehouse,
            'converter' => $converter,
            'base' => $converter->base(),
            'expiries' => $expiries,
            'total' => $total,
            'nearest' => $nearest,
            'expiringDays' => StockOnHandReport::EXPIRING_DAYS,
            'today' => $today,
            'arriving' => $arriving,
            'reserved' => $reserved,
            'holders' => $holders,
            'serials' => $serials,
            'stockCard' => Gate::allows(Reports::get('stock-card')->permission())
                ? route('reports.stock-card.item', ['product' => $product->id, 'warehouse' => $warehouse->id])
                : null,
            'larger' => collect($converter->factors())
                ->reject(fn (float $factor, string $unit) => $unit === $converter->base())
                ->map(fn (float $factor, string $unit) => __('reports.item.per_unit', ['unit' => Format::unit($unit), 'qty' => Format::qtyUnit($factor, $converter->base())]))
                ->values(),
        ]);
    }
}
