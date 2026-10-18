<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * A unit of measure (Piece, Box, Pallet...). Shared by all customers.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 */
class Unit extends Model
{
    use HasNumericKey;

    protected $table = 'unit';

    // The table only has created_at.
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * Translated name (lang/<locale>/units.php by code), falling back to the stored name.
     */
    public function label(): string
    {
        return Lang::has('units.'.$this->code) ? __('units.'.$this->code) : $this->name;
    }

    /**
     * A unit is in use once any item, barcode or document line refers to it.
     */
    public function inUse(): bool
    {
        return DB::table('product')->where('base_unit_id', $this->id)->exists()
            || DB::table('product_unit')->where('unit_id', $this->id)->exists()
            || DB::table('product_barcode')->where('unit_id', $this->id)->exists()
            || DB::table('stock_transaction_line')->where('unit_id', $this->id)->exists();
    }
}
