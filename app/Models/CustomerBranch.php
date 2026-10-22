<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a customer's branches (shop, site). Draft receipts say which branch
 * the goods come from, draft issues which branch they go to; information only,
 * stock is never kept per branch.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property bool $active
 */
class CustomerBranch extends Model
{
    use CustomerScoped, HasNumericKey;

    protected $table = 'customer_branch';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'code',
        'name',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
