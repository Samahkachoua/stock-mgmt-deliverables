<?php

namespace App\Services\Items;

use App\Models\Product;
use App\Support\Audit;

/**
 * Saves an item with its units and records it in the audit log: the one way
 * items are written, from the item form and from the Excel import. Callers
 * validate first and run this inside their DB::transaction.
 */
final class ItemWriter
{
    /**
     * @param  array{customer_id: string, sku: string, name_en: string, name_ar: ?string, name_fr: ?string, base_unit_id: string, track_expiry: bool, track_serial: bool, approval_threshold_qty: ?string, active: bool}  $attributes
     * @param  list<array{unit_id: string, factor: string|float}>  $units  alternate units (ignored for serial items)
     */
    public function save(Product $product, array $attributes, array $units, ?string $userId = null): Product
    {
        $isNew = ! $product->exists;
        $before = $isNew ? null : $this->snapshot($product);

        $product->fill($attributes)->save();

        // Rewrite the unit rows: the base unit's own row (factor 1) first, so
        // trg_validate_product_unit always sees the current base unit.
        $product->units()->delete();
        $product->units()->create(['unit_id' => $attributes['base_unit_id'], 'factor_to_base' => 1, 'is_default' => true]);

        if (! $attributes['track_serial']) {
            foreach ($units as $row) {
                $product->units()->create(['unit_id' => $row['unit_id'], 'factor_to_base' => $row['factor'], 'is_default' => false]);
            }
        }

        $after = $this->snapshot($product->refresh());

        if ($before !== $after) {
            Audit::record('product', $product->id, $isNew ? 'CREATE' : 'UPDATE', $before, $after, $userId);
        }

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Product $product): array
    {
        $product->load(['customer', 'baseUnit', 'units.unit']);

        return [
            'customer' => $product->customer->code,
            'sku' => $product->sku,
            'name_en' => $product->name_en,
            'name_ar' => $product->name_ar,
            'name_fr' => $product->name_fr,
            'base_unit' => $product->baseUnit->code,
            'units' => $product->units->mapWithKeys(fn ($row) => [$row->unit->code => $row->factor_to_base])->sortKeys()->all(),
            'track_expiry' => $product->track_expiry,
            'track_serial' => $product->track_serial,
            'approval_threshold_qty' => $product->approval_threshold_qty,
            'active' => $product->active,
        ];
    }
}
