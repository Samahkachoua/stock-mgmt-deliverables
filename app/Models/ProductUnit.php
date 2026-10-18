<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "1 <unit> = factor_to_base base units" for one item. The base unit has its
 * own row with factor 1 (is_default); other units convert to more than 1
 * (trg_validate_product_unit).
 *
 * @property string $id
 * @property string $product_id
 * @property string $unit_id
 * @property float $factor_to_base
 * @property bool $is_default
 * @property-read Unit $unit
 */
class ProductUnit extends Model
{
    use HasNumericKey;

    protected $table = 'product_unit';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'unit_id',
        'factor_to_base',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factor_to_base' => 'float',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
