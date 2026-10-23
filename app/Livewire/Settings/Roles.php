<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings › Roles: a roles × permissions matrix (role_permission), grouped by
 * permission module. System roles can't be deleted; other roles can be added
 * and, once nobody has them, deleted. Every change is written to audit_log and
 * clears the cached permission map.
 *
 * @property-read Collection<int, Role> $roles
 * @property-read \Illuminate\Support\Collection<string, Collection<int, Permission>> $modules
 */
#[Layout('components.layouts.app')]
class Roles extends Component
{
    use ConfirmsActions;

    /** @var array<string, list<string>> role id => granted permission ids */
    public array $grants = [];

    public bool $adding = false;

    public string $newCode = '';

    public string $newName = '';

    public function mount(): void
    {
        Gate::authorize('admin.users.manage');

        $this->loadGrants();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::withCount('users')->orderByDesc('is_system')->orderBy('created_at')->orderBy('code')->get();
    }

    /**
     * @return \Illuminate\Support\Collection<string, Collection<int, Permission>>
     */
    #[Computed]
    public function modules(): \Illuminate\Support\Collection
    {
        return Permission::orderBy('module')->orderBy('code')->get()->groupBy('module');
    }

    public function save(): void
    {
        Gate::authorize('admin.users.manage');

        // All permission ids at once ('exists' would query once per ticked box).
        $codes = Permission::pluck('code', 'id');

        $this->validate([
            'grants' => ['array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['integer', Rule::in($codes->keys()->all())],
        ]);

        if (! $this->keepsOwnUserManagement()) {
            $this->addError('grants', __('settings.roles.errors.keep_own_admin'));

            return;
        }

        DB::transaction(function () use ($codes) {
            $current = $this->currentGrants();

            foreach ($this->roles as $role) {
                $before = $current[$role->id] ?? [];
                $after = array_values(array_unique($this->grants[$role->id] ?? []));

                if (collect($before)->sort()->values()->all() === collect($after)->sort()->values()->all()) {
                    continue;
                }

                $role->permissions()->sync($after);

                Audit::record('role', $role->id, 'UPDATE',
                    ['code' => $role->code, 'permissions' => $codes->only($before)->sort()->values()->all()],
                    ['code' => $role->code, 'permissions' => $codes->only($after)->sort()->values()->all()],
                );
            }
        });

        Permissions::flush();
        $this->loadGrants();
        Notify::success(__('settings.roles.saved'), on: $this);
    }

    public function add(): void
    {
        Gate::authorize('admin.users.manage');

        $this->resetValidation();
        $this->adding = true;
        $this->newCode = '';
        $this->newName = '';
    }

    public function cancelAdd(): void
    {
        $this->adding = false;
        $this->resetValidation();
    }

    public function updatedNewCode(): void
    {
        $this->newCode = mb_strtoupper(trim($this->newCode));
        $this->validateOnly('newCode', $this->newRoleRules(), $this->newRoleMessages());
    }

    public function create(): void
    {
        Gate::authorize('admin.users.manage');

        $this->newCode = mb_strtoupper(trim($this->newCode));
        $this->validate($this->newRoleRules(), $this->newRoleMessages());

        $role = Role::create(['code' => $this->newCode, 'name' => trim($this->newName)]);

        Audit::record('role', $role->id, 'CREATE', null, ['code' => $role->code, 'name' => $role->name, 'permissions' => []]);

        $this->grants[$role->id] = [];
        $this->adding = false;
        Notify::success(__('settings.roles.added', ['name' => $role->name]), on: $this);
        unset($this->roles);
    }

    /**
     * "Delete" under a role: asks first, unless it is a built-in role or
     * someone still has it: then it says why it cannot be deleted instead.
     */
    public function askDelete(string $id): void
    {
        Gate::authorize('admin.users.manage');

        $role = Role::withCount('users')->findOrFail($id);
        $this->resetErrorBag('delete.'.$id);

        if ($blocked = $this->deleteBlocked($role)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        $this->askToConfirm(
            'delete', $id,
            __('confirm.delete.role.title', ['name' => $role->label()]),
            __('confirm.delete.role.body'),
            __('confirm.delete.role.button'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action === 'delete') {
            $this->delete($id);
        }
    }

    private function deleteBlocked(Role $role): ?string
    {
        return match (true) {
            (bool) $role->is_system => __('confirm.blocked.role_system', ['name' => $role->label()]),
            $role->users_count > 0 => trans_choice('confirm.blocked.role_in_use', $role->users_count, ['name' => $role->label(), 'count' => Format::qty($role->users_count)]),
            default => null,
        };
    }

    private function delete(string $id): void
    {
        Gate::authorize('admin.users.manage');

        $role = Role::withCount('users')->findOrFail($id);

        if ($blocked = $this->deleteBlocked($role)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        Audit::record('role', $role->id, 'DELETE', [
            'code' => $role->code,
            'name' => $role->name,
            'permissions' => $role->permissions()->orderBy('code')->pluck('code')->all(),
        ], null);

        $role->delete(); // role_permission rows go with it (ON DELETE CASCADE)

        Permissions::flush();
        unset($this->grants[$id], $this->roles);
        Notify::success(__('settings.roles.deleted', ['name' => $role->name]), on: $this);
    }

    public function render(): View
    {
        return view('livewire.settings.roles', [
            'allPermissionIds' => $this->modules->flatten()->map(fn (Permission $permission) => (string) $permission->id)->values()->all(),
        ])->title(__('settings.tabs.roles'));
    }

    /**
     * After the change, the signed-in user's own roles must still let them manage users.
     */
    private function keepsOwnUserManagement(): bool
    {
        $codes = Permission::pluck('code', 'id');
        $ownRoles = Auth::user()?->roles()->pluck('role.id') ?? collect();

        return $ownRoles
            ->flatMap(fn (string $roleId) => $this->grants[$roleId] ?? [])
            ->map(fn (string $permissionId) => $codes[$permissionId] ?? null)
            ->contains('admin.users.manage');
    }

    /**
     * @return array<string, list<string>>
     */
    private function currentGrants(): array
    {
        $grants = [];

        foreach (DB::table('role_permission')->get(['role_id', 'permission_id']) as $row) {
            $grants[$row->role_id][] = $row->permission_id;
        }

        return $grants;
    }

    private function loadGrants(): void
    {
        $current = $this->currentGrants();

        $this->grants = $this->roles
            ->mapWithKeys(fn (Role $role) => [$role->id => $current[$role->id] ?? []])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function newRoleRules(): array
    {
        return [
            'newCode' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('role', 'code')],
            'newName' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function newRoleMessages(): array
    {
        return [
            'newCode.required' => __('settings.roles.errors.code_required'),
            'newCode.max' => __('settings.errors.too_long', ['max' => 50]),
            'newCode.regex' => __('settings.roles.errors.code_format'),
            'newCode.unique' => __('settings.roles.errors.code_taken'),
            'newName.required' => __('settings.roles.errors.name_required'),
            'newName.max' => __('settings.errors.too_long', ['max' => 100]),
        ];
    }
}
