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
 * @property string|null $customer_id set for the customer's own staff (a customer user)
 * @property string|null $default_warehouse_id where pages start; see defaultWarehouseId()
 * @property string $preferred_lang
 * @property string $preferred_theme
 * @property bool $notify_new_orders email about orders they may confirm (App\Support\Drafts\DraftNotifier)
 * @property bool $notify_draft_receipts email about draft receipts they may confirm (App\Support\Drafts\DraftNotifier)
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

    /** @var list<string>|null */
    private ?array $branchIds = null;

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
            'active' => 'boolean',
            'notify_new_orders' => 'boolean',
            'notify_draft_receipts' => 'boolean',
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
     * The warehouse forms, lists and reports start on: the user's default
     * (app_user.default_warehouse_id) while it is still one of their active
     * warehouses, else the first of those by name; null when they have none.
     */
    public function defaultWarehouseId(): ?string
    {
        if ($this->default_warehouse_id !== null && $this->canAccessWarehouse((string) $this->default_warehouse_id)) {
            return (string) $this->default_warehouse_id;
        }

        return $this->assignedWarehouses()->first()?->id;
    }

    /**
     * The customer's own staff: a user linked to a customer. They only ever see
     * that customer's data (CustomerScope).
     */
    public function isCustomerUser(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Branches of their customer a customer user works for (optional).
     *
     * @return BelongsToMany<CustomerBranch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(CustomerBranch::class, 'user_customer_branch', 'user_id', 'branch_id');
    }

    /**
     * Ids of the branches this user is limited to; empty = no branch limit
     * (staff, or a customer user linked to none). Memoized for the request.
     *
     * @return list<string>
     */
    public function branchIds(): array
    {
        return $this->branchIds ??= $this->isCustomerUser() ? $this->branches()->pluck('customer_branch.id')->all() : [];
    }

    /**
     * Whether this user may work with drafts of the given branch: any branch for
     * staff; for a customer user, a branch of their customer, and one of theirs
     * when they are linked to some.
     */
    public function canUseBranch(string $branchId, string $customerId): bool
    {
        if (! $this->isCustomerUser()) {
            return true;
        }

        return $customerId === $this->customer_id && ($this->branchIds() === [] || in_array($branchId, $this->branchIds(), true));
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
