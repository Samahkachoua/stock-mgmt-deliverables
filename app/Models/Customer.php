<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $active
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use CustomerScoped, HasFactory, HasNumericKey;

    protected $table = 'customer';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'contact_email',
        'contact_phone',
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
     * A customer user sees only their own customer row.
     */
    public function customerColumn(): string
    {
        return 'id';
    }
}
