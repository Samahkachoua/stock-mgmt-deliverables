<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use App\Models\Concerns\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A stock document header (Receive, Delivery note, Transfer, ...). Visible from its
 * source warehouse and, for transfers, its destination warehouse.
 *
 * @property string $id
 * @property string $doc_no
 * @property string $transaction_type
 * @property string $status
 * @property string $warehouse_id
 * @property string|null $to_warehouse_id
 * @property string|null $customer_id
 * @property string|null $reason_code
 * @property string $created_by
 */
class StockTransaction extends Model
{
    use CustomerScoped, HasNumericKey, WarehouseScoped;

    protected $table = 'stock_transaction';

    /**
     * @return list<string>
     */
    public function warehouseColumns(): array
    {
        return ['warehouse_id', 'to_warehouse_id'];
    }
}
