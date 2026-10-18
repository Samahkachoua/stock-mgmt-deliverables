<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Real customers, items and stock for tests of the transaction screens:
 *
 *   Acme Foods   CB-400 Canned Beans   Piece, 1 Box = 12        64 in stock
 *                OO-1L  Olive Oil      Bottle, 1 Carton = 12   120 in stock
 *                RC-5K  Rice 5kg       Bag                      45 in stock
 *                YG-500 Yogurt         Piece, 1 Box = 6, expiry: 24 (+14 days), 36 (+40 days)
 *                OLD-1  Discontinued   inactive
 *                FIL-01..25 fillers, nothing in stock
 *   Cedar Trading CT-T12 Cotton T-shirt Piece, 1 Carton = 50   400 in stock
 *                 ES-MCH Espresso Machine, serial numbers
 *
 * Stock rows are inserted directly: a test fixture, not application code.
 */
final class Catalog
{
    /** @var array<string, Product> */
    public array $items = [];

    private function __construct(
        public readonly Warehouse $warehouse,
        public readonly Customer $acme,
        public readonly Customer $cedar,
    ) {}

    public static function create(): self
    {
        $catalog = new self(
            Warehouse::factory()->create(['name' => 'Beirut Main', 'code' => 'BEY']),
            Customer::factory()->create(['code' => 'ACME', 'name' => 'Acme Foods']),
            Customer::factory()->create(['code' => 'CEDAR', 'name' => 'Cedar Trading']),
        );

        Unit::firstOrCreate(['code' => 'BTL'], ['name' => 'Bottle']);
        Unit::firstOrCreate(['code' => 'BAG'], ['name' => 'Bag']);

        $catalog->item($catalog->acme, 'CB-400', ['Canned Beans 400g', 'فول معلّب', 'Haricots'], 'PC', ['BOX' => 12], stock: 64);
        $catalog->item($catalog->acme, 'OO-1L', ['Olive Oil 1L', 'زيت زيتون', 'Huile d’olive'], 'BTL', ['CTN' => 12], stock: 120);
        $catalog->item($catalog->acme, 'RC-5K', ['Rice 5kg', 'أرز', 'Riz'], 'BAG', [], stock: 45);
        $yogurt = $catalog->item($catalog->acme, 'YG-500', ['Yogurt 500g', 'لبن', 'Yaourt'], 'PC', ['BOX' => 6], expiry: true);
        $catalog->expiring($yogurt, now()->addDays(14)->toDateString(), 24);
        $catalog->expiring($yogurt, now()->addDays(40)->toDateString(), 36);
        $catalog->item($catalog->acme, 'OLD-1', ['Discontinued Item', null, null], 'PC', [], active: false);

        foreach (range(1, 25) as $n) {
            $catalog->item($catalog->acme, sprintf('FIL-%02d', $n), [sprintf('Filler %02d', $n), null, null], 'PC', []);
        }

        $catalog->item($catalog->cedar, 'CT-T12', ['Cotton T-shirt', 'قميص قطني', 'T-shirt'], 'PC', ['CTN' => 50], stock: 400);
        $catalog->item($catalog->cedar, 'ES-MCH', ['Espresso Machine', 'آلة إسبريسو', 'Machine à espresso'], 'PC', [], serial: true);

        return $catalog;
    }

    public function id(string $code): string
    {
        return $this->items[$code]->id;
    }

    /**
     * A user with the role in this warehouse, which becomes the current warehouse.
     */
    public function user(string $role = 'ADMIN'): User
    {
        session([EnsureCurrentWarehouse::SESSION_KEY => $this->warehouse->id]);

        return userWithRole($role, $this->warehouse);
    }

    /**
     * @param  array{0: string, 1: string|null, 2: string|null}  $names
     * @param  array<string, int>  $units
     */
    public function item(
        Customer $customer,
        string $code,
        array $names,
        string $base,
        array $units,
        float $stock = 0,
        bool $expiry = false,
        bool $serial = false,
        bool $active = true,
    ): Product {
        $unitIds = Unit::pluck('id', 'code');

        $product = Product::create([
            'customer_id' => $customer->id,
            'sku' => $code,
            'name_en' => $names[0],
            'name_ar' => $names[1],
            'name_fr' => $names[2],
            'base_unit_id' => $unitIds[$base],
            'track_expiry' => $expiry,
            'track_serial' => $serial,
            'active' => $active,
        ]);

        $product->units()->create(['unit_id' => $unitIds[$base], 'factor_to_base' => 1, 'is_default' => true]);

        foreach ($units as $unit => $factor) {
            $product->units()->create(['unit_id' => $unitIds[$unit], 'factor_to_base' => $factor, 'is_default' => false]);
        }

        if ($stock > 0) {
            $this->stock($product, $stock);
        }

        return $this->items[$code] = $product;
    }

    public function stock(Product $product, float $qty, ?string $expiry = null): void
    {
        DB::table('stock_balance')->insert([
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $product->customer_id,
            'product_id' => $product->id,
            'expiry_date' => $expiry,
            'qty_base' => $qty,
        ]);
    }

    /** Stock of an expiry item with one expiry date (Y-m-d). */
    public function expiring(Product $product, string $expiry, float $qty): void
    {
        $this->stock($product, $qty, $expiry);
    }
}
