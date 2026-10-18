<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WarehouseScope;

/**
 * Use on every model whose rows belong to a warehouse (CLAUDE.md: warehouse
 * scoping). Adds WarehouseScope; bypass it only deliberately, with
 * withoutGlobalScope(WarehouseScope::class).
 *
 * Override warehouseColumns() when a row has more than one warehouse column,
 * e.g. ['warehouse_id', 'to_warehouse_id'] for stock_transaction.
 */
trait WarehouseScoped
{
    public static function bootWarehouseScoped(): void
    {
        static::addGlobalScope(new WarehouseScope);
    }

    /**
     * @return list<string>
     */
    public function warehouseColumns(): array
    {
        return ['warehouse_id'];
    }
}
