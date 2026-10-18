<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo items for local development: two customers, food with expiry dates and
 * Box / Carton / Pallet packaging, household goods, and one serial-numbered item.
 * Runs after LocalSeeder created the customers. Safe to run again.
 */
class ItemSeeder extends Seeder
{
    /**
     * customer code => [code, [en, ar, fr], base unit, [unit => factor], expiry, serial]
     *
     * @var array<string, list<array{0: string, 1: array{0: string, 1: string, 2: string}, 2: string, 3: array<string, int>, 4?: bool, 5?: bool}>>
     */
    private const ITEMS = [
        'ACME' => [
            ['CB-400', ['Canned Beans 400g', 'فول معلّب 400 غ', 'Haricots en conserve 400 g'], 'PC', ['BOX' => 12, 'PLT' => 480], true],
            ['OO-1L', ['Olive Oil 1L', 'زيت زيتون 1 ل', 'Huile d’olive 1 L'], 'BTL', ['CTN' => 12, 'PLT' => 600], true],
            ['RC-5K', ['Rice 5kg', 'أرز 5 كغ', 'Riz 5 kg'], 'BAG', ['PLT' => 100]],
            ['TP-200', ['Tomato Paste 200g', 'معجون طماطم 200 غ', 'Concentré de tomate 200 g'], 'PC', ['CTN' => 24, 'PLT' => 1440], true],
            ['LN-1K', ['Lentils 1kg', 'عدس 1 كغ', 'Lentilles 1 kg'], 'BAG', ['CTN' => 10]],
            ['YG-500', ['Yogurt 500g', 'لبن 500 غ', 'Yaourt 500 g'], 'PC', ['BOX' => 6], true],
            ['SG-1K', ['Sugar 1kg', 'سكر 1 كغ', 'Sucre 1 kg'], 'BAG', ['CTN' => 10, 'PLT' => 800]],
            ['MK-1L', ['UHT Milk 1L', 'حليب طويل الأجل 1 ل', 'Lait UHT 1 L'], 'BTL', ['CTN' => 12, 'PLT' => 720], true],
            ['CF-BN', ['Coffee Beans', 'حبوب البن', 'Café en grains'], 'KG', ['BAG' => 25]],
        ],
        'CEDAR' => [
            ['CT-T12', ['Cotton T-shirt', 'قميص قطني', 'T-shirt en coton'], 'PC', ['CTN' => 50]],
            ['WL-SC', ['Wool Scarf', 'وشاح صوف', 'Écharpe en laine'], 'PC', ['BOX' => 10]],
            ['LT-BG', ['Leather Bag', 'حقيبة جلدية', 'Sac en cuir'], 'PC', []],
            ['ES-MCH', ['Espresso Machine', 'آلة إسبريسو', 'Machine à espresso'], 'PC', [], false, true],
            ['DS-5L', ['Dish Soap 5L', 'سائل جلي 5 ل', 'Liquide vaisselle 5 L'], 'BTL', ['CTN' => 4, 'PLT' => 160]],
            ['BL-1L', ['Bleach 1L', 'مبيّض 1 ل', 'Eau de Javel 1 L'], 'BTL', ['CTN' => 12], true],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ItemSeeder must never run in production.');
        }

        // schema.sql seeds PC, BOX, PLT, KG and CTN.
        Unit::updateOrCreate(['code' => 'BTL'], ['name' => 'Bottle']);
        Unit::updateOrCreate(['code' => 'BAG'], ['name' => 'Bag']);
        $units = Unit::pluck('id', 'code');

        DB::transaction(function () use ($units) {
            foreach (self::ITEMS as $customerCode => $items) {
                $customer = Customer::where('code', $customerCode)->firstOrFail();

                foreach ($items as $item) {
                    [$code, [$en, $ar, $fr], $base, $others] = $item;

                    $product = Product::updateOrCreate(
                        ['customer_id' => $customer->id, 'sku' => $code],
                        [
                            'name_en' => $en,
                            'name_ar' => $ar,
                            'name_fr' => $fr,
                            'base_unit_id' => $units[$base],
                            'track_expiry' => $item[4] ?? false,
                            'track_serial' => $item[5] ?? false,
                            'active' => true,
                        ],
                    );

                    // Same order as the item form: the base unit's own row first, at factor 1.
                    $product->units()->delete();
                    $product->units()->create(['unit_id' => $units[$base], 'factor_to_base' => 1, 'is_default' => true]);

                    foreach ($others as $unit => $factor) {
                        $product->units()->create(['unit_id' => $units[$unit], 'factor_to_base' => $factor, 'is_default' => false]);
                    }
                }
            }
        });
    }
}
