<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasNumericKey;
use App\Models\Concerns\WarehouseScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A warehouse-bound model over a temporary table (see WarehouseScopeTest).
 */
class ScopedRecord extends Model
{
    use HasNumericKey, WarehouseScoped;

    protected $table = 'scoped_record';

    public $timestamps = false;

    protected $guarded = [];
}
