<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use App\Models\Concerns\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable ledger row. Read-only here: rows are written only by
 * fn_post_movement through App\Services\Stock\PostingService.
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $warehouse_id
 * @property string $customer_id
 * @property string $product_id
 * @property string|null $expiry_date
 * @property string $direction
 * @property string $qty_base
 */
class StockMovement extends Model
{
    use CustomerScoped, HasNumericKey, WarehouseScoped;

    protected $table = 'stock_movement';

    // The table only has created_at.
    const UPDATED_AT = null;
}
