<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * The balance cache maintained by fn_apply_balance. Read-only: never update it
 * directly (CLAUDE.md rule 1). Its key is composite, so rows are queried, not
 * found by id.
 *
 * @property string $warehouse_id
 * @property string $customer_id
 * @property string $product_id
 * @property string|null $expiry_date
 * @property string $qty_base
 */
class StockBalance extends Model
{
    use CustomerScoped, WarehouseScoped;

    protected $table = 'stock_balance';

    protected $primaryKey = null;

    public $incrementing = false;

    // Only updated_at, set by the database.
    public $timestamps = false;
}
