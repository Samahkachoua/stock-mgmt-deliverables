<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * A row of transaction_type. Codes live in the database (CLAUDE.md rule 4);
 * never match on them in PHP.
 *
 * @property string $code
 * @property string $name
 * @property bool $requires_destination_warehouse
 * @property bool $requires_dispatch_receive
 * @property bool $supports_approval_threshold
 * @property int $display_order
 * @property bool $active
 * @property string|null $posting_direction IN / OUT; NULL when decided per line or in two steps
 * @property bool $uses_expired may take expired stock
 */
class TransactionType extends Model
{
    protected $table = 'transaction_type';

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
            'requires_destination_warehouse' => 'boolean',
            'requires_dispatch_receive' => 'boolean',
            'supports_approval_threshold' => 'boolean',
            'uses_expired' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Translated name (lang/<locale>/types.php, keyed by the type's token in
     * config/transactions.php), falling back to the name stored in the database.
     */
    public function label(): string
    {
        $key = 'types.'.config('transactions.types.'.$this->code.'.token');

        return Lang::has($key) ? __($key) : $this->name;
    }
}
