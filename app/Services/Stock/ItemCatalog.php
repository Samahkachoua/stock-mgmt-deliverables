<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Support\Ids;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Items as the transaction screens need them: a customer's active items with
 * their units and what is in stock in one warehouse (read from stock_balance,
 * never written here).
 *
 * With $excludeExpired (types that skip expired stock, e.g. Issue), `available`
 * counts only stock that has not expired; `expired` says how much has.
 * `expiries` is the stock of an expiry item per expiry date, nearest first.
 *
 * @phpstan-type CatalogItem array{id: string, code: string, name: string, base: string, units: array<string, float>, available: float, expired: float, expiry: bool, serial: bool, expiries: list<array{expiry: string, qty: float, expired: bool}>}
 */
final class ItemCatalog
{
    public const MAX_RESULTS = 20;

    /**
     * The customer's active items whose code or name (any language) contains $search, at most $limit.
     *
     * @return list<CatalogItem>
     */
    public static function search(string $customerId, string $search, ?string $warehouseId, int $limit = self::MAX_RESULTS, bool $excludeExpired = false): array
    {
        if (! Ids::valid($customerId)) {
            return [];
        }

        $products = Product::query()
            ->where('customer_id', $customerId)
            ->where('active', true)
            ->when(trim($search) !== '', fn ($query) => $query->search($search))
            ->with(['baseUnit', 'units.unit'])
            ->orderBy('name_en')
            ->limit($limit)
            ->get();

        return self::describe($products, $customerId, $warehouseId, $excludeExpired);
    }

    /**
     * One active item of the customer, or null.
     *
     * @return CatalogItem|null
     */
    public static function item(string $customerId, ?string $productId, ?string $warehouseId, bool $excludeExpired = false): ?array
    {
        if ($productId === null || ! Ids::valid($productId) || ! Ids::valid($customerId)) {
            return null;
        }

        $products = Product::query()
            ->whereKey($productId)
            ->where('customer_id', $customerId)
            ->where('active', true)
            ->with(['baseUnit', 'units.unit'])
            ->get();

        return self::describe($products, $customerId, $warehouseId, $excludeExpired)[0] ?? null;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<CatalogItem>
     */
    private static function describe(Collection $products, string $customerId, ?string $warehouseId, bool $excludeExpired): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $today = $warehouseId === null ? null : app(Availability::class)->today($warehouseId)->toDateString();

        // Stock here, per item and expiry date (base units), nearest expiry first.
        $balances = $warehouseId === null ? collect() : DB::table('stock_balance')
            ->where('warehouse_id', $warehouseId)
            ->where('customer_id', $customerId)
            ->whereIn('product_id', $products->modelKeys())
            ->where('qty_base', '>', 0)
            ->orderByRaw('expiry_date ASC NULLS LAST')
            ->get(['product_id', 'qty_base', 'expiry_date'])
            ->groupBy('product_id');

        $items = [];

        foreach ($products as $product) {
            $converter = UnitConverter::forProduct($product);
            $available = 0.0;
            $expired = 0.0;
            $expiries = [];

            foreach ($balances->get($product->id, collect()) as $row) {
                $isExpired = $row->expiry_date !== null && $row->expiry_date < $today;
                $qty = (float) $row->qty_base;

                if ($isExpired && $excludeExpired) {
                    $expired += $qty;
                } else {
                    $available += $qty;
                }

                if ($row->expiry_date !== null) {
                    $expiries[] = ['expiry' => $row->expiry_date, 'qty' => $qty, 'expired' => $isExpired];
                }
            }

            $items[] = [
                'id' => $product->id,
                'code' => $product->sku,
                'name' => $product->name(),
                'base' => $converter->base(),
                // Base unit first, then larger units smallest to largest (the unit dropdown order).
                'units' => array_reverse($converter->factors(), true),
                'available' => $available,
                'expired' => $expired,
                'expiry' => $product->track_expiry,
                'serial' => $product->track_serial,
                'expiries' => $expiries,
            ];
        }

        return $items;
    }
}
