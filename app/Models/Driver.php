<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A driver (Settings › Drivers), shared by all warehouses. A Delivery note is
 * dispatched with one and keeps a copy of their details at that moment.
 *
 * @property string $id
 * @property string $name
 * @property string $licence_no
 * @property string $national_id
 * @property string $phone
 * @property string $truck_no
 */
class Driver extends Model
{
    use HasNumericKey;

    protected $table = 'driver';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'licence_no',
        'national_id',
        'phone',
        'truck_no',
    ];

    /** How many delivery notes were dispatched with this driver (then it can't be deleted). */
    public function notesCount(): int
    {
        return DB::table('stock_transaction')->where('driver_id', $this->id)->count();
    }

    /** "Karim Haddad · TR-77", as the dispatch dropdown shows it. */
    public function label(): string
    {
        return $this->name.' · '.$this->truck_no;
    }
}
