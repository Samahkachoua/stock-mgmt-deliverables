<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Support\Batches;
use App\Support\Ids;
use App\Support\StorageStatuses;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Items as the transaction screens need them: a customer's active items with
 * their units and what is in stock in one warehouse (read from stock_balance,
 * never written here).
 *
 * `available` is what may be taken: stock not reserved for Orders / Delivery
 * notes (with $ownDraftId, what that Order holds counts as its own). With
 * $excludeExpired (types that skip expired stock, e.g. Delivery note) it counts
 * only stock that has not expired; `expired` says how much has. `in_stock` is
 * the whole balance and `reserved` the part held for orders (counts compare
 * with the whole balance).
 * `expiries` is the stock of an expiry item per lot (expiry date + batch, `lot`
 * = Batches::key(); batch '' = stock without a batch), nearest expiry first:
 * `qty` available, `total` in stock, `reserved` held (a lot all reserved has qty 0).
 * Services (no stock) are left out unless $withServices (types that take them,
 * TypeUi::allowsServices()); a service has `service` true and no stock.
 *
 * @phpstan-type CatalogItem array{id: string, code: string, name: string, storage: ?string, base: string, units: array<string, float>, available: float, in_stock: float, reserved: float, expired: float, expiry: bool, serial: bool, service: bool, expiries: list<array{expiry: string, batch: string, lot: string, qty: float, total: float, reserved: float, expired: bool}>}
 */
final class ItemCatalog
{
    public const MAX_RESULTS = 20;

    /**
     * The customer's active items whose code or name (any language) contains $search, at most $limit.
     *
     * @return list<CatalogItem>
     */
    public static function search(string $customerId, string $search, ?string $warehouseId, int $limit = self::MAX_RESULTS, bool $excludeExpired = false, bool $withServices = false, ?string $ownDraftId = null): array
    {
        if (! Ids::valid($customerId)) {
            return [];
        }

        $products = Product::query()
            ->where('customer_id', $customerId)
            ->where('active', true)
            ->when(! $withServices, fn ($query) => $query->stocked())
            ->when(trim($search) !== '', fn ($query) => $query->search($search))
            ->with(['baseUnit', 'units.unit'])
            ->orderBy('name_en')
            ->limit($limit)
            ->get();

        return self::describe($products, $customerId, $warehouseId, $excludeExpired, ownDraftId: $ownDraftId);
    }

    /**
     * One active item of the customer, or null. With a $date before today (stock
     * going out on a document dated back), its stock is what may go out on
     * that date (Availability::onDate()).
     *
     * @return CatalogItem|null
     */
    public static function item(string $customerId, ?string $productId, ?string $warehouseId, bool $excludeExpired = false, ?CarbonInterface $date = null, bool $withServices = false, ?string $ownDraftId = null): ?array
    {
        if ($productId === null) {
            return null;
        }

        return self::items($customerId, [$productId], $warehouseId, $excludeExpired, $date, $withServices, $ownDraftId)[$productId] ?? null;
    }

    /**
     * Several active items of the customer at once (the rows of a form), by id;
     * ids that are not one of them are left out. Same queries as one item.
     *
     * @param  list<string>  $productIds
     * @return array<string, CatalogItem>
     */
    public static function items(string $customerId, array $productIds, ?string $warehouseId, bool $excludeExpired = false, ?CarbonInterface $date = null, bool $withServices = false, ?string $ownDraftId = null): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, fn ($id) => Ids::valid($id))));

        if ($productIds === [] || ! Ids::valid($customerId)) {
            return [];
        }

        $products = Product::query()
            ->whereKey($productIds)
            ->where('customer_id', $customerId)
            ->where('active', true)
            ->when(! $withServices, fn ($query) => $query->stocked())
            ->with(['baseUnit', 'units.unit'])
            ->get();

        return array_column(self::describe($products, $customerId, $warehouseId, $excludeExpired, $date, $ownDraftId), null, 'id');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<CatalogItem>
     */
    private static function describe(Collection $products, string $customerId, ?string $warehouseId, bool $excludeExpired, ?CarbonInterface $date = null, ?string $ownDraftId = null): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $today = $warehouseId === null ? null : app(Availability::class)->today($warehouseId)->toDateString();

        // Stock here, per item and lot (base units), nearest expiry first.
        $balances = $warehouseId === null ? collect() : DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->whereIn('product_id', $products->modelKeys())
            ->where('qty_base', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST, batch_key ASC')
            ->get(['product_id', 'qty_base', 'qty_reserved', 'expiry_date', 'batch_key'])
            ->groupBy('product_id');

        // An Order being edited: what it holds itself is available to it.
        $own = $ownDraftId === null || $warehouseId === null ? collect() : DB::table('stock_reservation as r')
            ->join('stock_draft_line as l', 'l.id', '=', 'r.draft_line_id')
            ->where('l.draft_id', $ownDraftId)
            ->where('r.warehouse_id', $warehouseId)
            ->groupBy('r.product_id', 'r.expiry_date', 'r.batch_no')
            ->get(['r.product_id', 'r.expiry_date', 'r.batch_no', DB::raw('sum(r.qty_base) as qty')])
            ->keyBy(fn ($row) => $row->product_id.'|'.Batches::key($row->expiry_date, $row->batch_no));

        // Dated back: what each item had on that day, all items in one query.
        $onDate = $warehouseId !== null && app(Availability::class)->isBackdated($warehouseId, $date)
            ? app(Availability::class)->onDateForItems($warehouseId, $customerId, $products->modelKeys(), $date)
            : null;

        $items = [];

        foreach ($products as $product) {
            $converter = UnitConverter::forProduct($product);
            $available = 0.0;
            $inStock = 0.0;
            $reserved = 0.0;
            $expired = 0.0;
            $expiries = [];
            $then = $onDate === null ? null : ($onDate[$product->id] ?? []);

            foreach ($balances->get($product->id, collect()) as $row) {
                $isExpired = $row->expiry_date !== null && $row->expiry_date < $today;
                $lot = Batches::key($row->expiry_date, $row->batch_key);
                $total = $then === null ? (float) $row->qty_base : min((float) $row->qty_base, $then[$lot] ?? 0.0);
                $held = max((float) $row->qty_reserved - (float) ($own->get($product->id.'|'.$lot)->qty ?? 0), 0.0);
                $qty = max(min($total, (float) $row->qty_base - $held), 0.0);

                if ($total <= 0) {
                    continue;
                }

                $inStock += $total;
                $reserved += $held;

                if ($isExpired && $excludeExpired) {
                    $expired += $qty;
                } else {
                    $available += $qty;
                }

                if ($row->expiry_date !== null) {
                    $expiries[] = ['expiry' => $row->expiry_date, 'batch' => (string) $row->batch_key, 'lot' => $lot, 'qty' => $qty, 'total' => $total, 'reserved' => $held, 'expired' => $isExpired];
                }
            }

            $items[] = [
                'id' => $product->id,
                'code' => $product->sku,
                'name' => $product->name(),
                'storage' => StorageStatuses::label($product->storage_status),
                'base' => $converter->base(),
                // Base unit first, then larger units smallest to largest (the unit dropdown order).
                'units' => array_reverse($converter->factors(), true),
                'available' => $available,
                'in_stock' => $inStock,
                'reserved' => $reserved,
                'expired' => $expired,
                'expiry' => $product->track_expiry,
                'serial' => $product->track_serial,
                'service' => $product->is_service,
                'expiries' => $expiries,
            ];
        }

        return $items;
    }
}
