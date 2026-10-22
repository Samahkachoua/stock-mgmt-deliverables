<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How cold an item must be kept (AMBIENT, COOL, CHILLED, FROZEN, DEEP_FROZEN),
 * with its range in degrees Celsius. Read through App\Support\StorageStatuses.
 *
 * @property string $code
 * @property int $temp_min_c
 * @property int $temp_max_c
 * @property int $display_order
 */
class StorageStatus extends Model
{
    protected $table = 'storage_status';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temp_min_c' => 'integer',
            'temp_max_c' => 'integer',
            'display_order' => 'integer',
        ];
    }
}
