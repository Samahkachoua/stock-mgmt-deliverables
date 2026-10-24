<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a draft (its warehouse is the draft's).
 *
 * @property string $id
 * @property string $draft_id
 * @property int $line_no
 * @property string $product_id
 * @property string $unit_id
 * @property string $qty_entered
 * @property string $qty_base
 * @property string|null $expiry_date
 * @property list<string> $serials
 */
class StockDraftLine extends Model
{
    use HasNumericKey;

    protected $table = 'stock_draft_line';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'serials' => 'array',
        ];
    }
}
