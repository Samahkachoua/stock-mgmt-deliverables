<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $city
 * @property string|null $address
 * @property bool $active
 */
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, HasNumericKey;

    protected $table = 'warehouse';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'city',
        'address',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_warehouse', 'warehouse_id', 'user_id');
    }
}
