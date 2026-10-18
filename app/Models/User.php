<?php

namespace App\Models;

use App\Models\Concerns\HasNumericKey;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property string $id
 * @property string $email
 * @property string $full_name
 * @property string $password_hash
 * @property bool $is_customer_user
 * @property string|null $customer_id
 * @property string $preferred_lang
 * @property string $preferred_theme
 * @property bool $active
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasNumericKey, Notifiable;

    protected $table = 'app_user';

    protected $authPasswordName = 'password_hash';

    /** @var list<string>|null */
    private ?array $permissionCodes = null;

    /** @var list<string>|null */
    private ?array $warehouseIds = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'email',
        'password_hash',
        'preferred_lang',
        'preferred_theme',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'is_customer_user' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id');
    }

    /**
     * @return BelongsToMany<Warehouse, $this>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse', 'user_id', 'warehouse_id');
    }

    /**
     * Active warehouses this user may act on (user_warehouse), by name.
     *
     * @return Collection<int, Warehouse>
     */
    public function assignedWarehouses(): Collection
    {
        return $this->warehouses()->where('warehouse.active', true)->orderBy('warehouse.name')->get();
    }

    /**
     * Ids of the active warehouses this user may act on. Memoized for the request.
     *
     * @return list<string>
     */
    public function warehouseIds(): array
    {
        return $this->warehouseIds ??= $this->warehouses()
            ->where('warehouse.active', true)
            ->pluck('warehouse.id')
            ->all();
    }

    public function canAccessWarehouse(?string $warehouseId): bool
    {
        return $warehouseId !== null && in_array($warehouseId, $this->warehouseIds(), true);
    }

    /**
     * Permission codes granted through the user's roles; none when inactive.
     * Memoized for the request.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        if ($this->permissionCodes !== null) {
            return $this->permissionCodes;
        }

        // A just-created model has no `active` until refreshed; the column defaults to true.
        if ($this->active === false) {
            return $this->permissionCodes = [];
        }

        $byRole = Permissions::byRole();

        return $this->permissionCodes = $this->roles()->pluck('role.id')
            ->flatMap(fn (string $roleId) => $byRole[$roleId] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }
}
