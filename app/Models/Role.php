<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Lang;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $is_system
 * @property-read int|null $users_count
 */
class Role extends Model
{
    use HasNumericKey;

    protected $table = 'role';

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
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role', 'role_id', 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Translated name (lang/<locale>/roles.php by code), falling back to the stored name.
     */
    public function label(): string
    {
        $key = 'roles.'.$this->code;

        return Lang::has($key) ? __($key) : $this->name;
    }

    /**
     * Writes to this relation must be followed by Permissions::flush().
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'role_id', 'permission_id');
    }
}
