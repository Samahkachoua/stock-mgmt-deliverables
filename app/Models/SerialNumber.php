<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use App\Models\Concerns\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A serial-tracked unit and the warehouse it is in now (NULL until received,
 * so not-yet-received serials are hidden from warehouse users). The customer
 * comes from its product.
 *
 * @property string $id
 * @property string $product_id
 * @property string $serial_no
 * @property string|null $warehouse_id
 * @property string $status
 */
class SerialNumber extends Model
{
    use HasNumericKey, WarehouseScoped;

    protected $table = 'serial_number';
}
