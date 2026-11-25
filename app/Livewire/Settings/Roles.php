<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Livewire\Concerns\WarnsBeforeLeaving;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Ids;
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
 * Settings › Roles: one role at a time (the list at the start), its permissions
 * (role_permission) as a tree: section (as in the sidebar) › module › permission.
 * Changes to several roles are kept until Save, which writes them all. System
 * roles can't be deleted; other roles can be added and, once nobody has them,
 * deleted. Every change is written to audit_log and clears the cached
 * permission map.
 *
 * @property-read Collection<int, Role> $roles
 * @property-read Role|null $selectedRole
 * @property-read \Illuminate\Support\Collection<string, Collection<int, Permission>> $modules
 */
#[Layout('components.layouts.app')]
class Roles extends Component
{
    use ConfirmsActions;
    use WarnsBeforeLeaving;

    /** Where the chosen role is remembered (session, like a list's filters). */
    private const STATE_KEY = 'list_state.settings-roles.role';

    /** @var array<string, list<string>> role id => granted permission ids */
    public array $grants = [];

    /** The role shown in the tree. */
    public string $selectedRoleId = '';

    public bool $adding = false;

    public string $newCode = '';

    public string $newName = '';

    public function mount(): void
    {
        Gate::authorize('admin.users.manage');

        $this->loadGrants();
        $this->selectedRoleId = $this->validRoleId(session(self::STATE_KEY));
        $this->rememberSavedState();
    }

    public function selectRole(string $id): void
    {
        $this->selectedRoleId = $this->validRoleId($id);
        session([self::STATE_KEY => $this->selectedRoleId]);
    }

    /** The role dropdown on a phone. */
    public function updatedSelectedRoleId(): void
    {
        $this->selectRole($this->selectedRoleId);
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->roles->firstWhere('id', $this->selectedRoleId);
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
        return Permission::all()
            ->sortBy(fn (Permission $permission) => [Permission::moduleRank($permission->module), $permission->module, $permission->actionRank(), $permission->code])
            ->groupBy('module');
    }

    /**
     * The tree: sections in the sidebar's order, then "other"; modules in
     * Permission::MODULES order; permissions seeing → doing → cancelling.
     *
     * @return list<array{key: string, label: string, modules: list<array{key: string, label: string, permissions: Collection<int, Permission>}>}>
     */
    #[Computed]
    public function tree(): array
    {
        $sections = [];

        foreach ($this->modules as $module => $permissions) {
            $sections[Permission::section((string) $module)][] = [
                'key' => (string) $module,
                'label' => Permission::moduleLabel((string) $module),
                'permissions' => $permissions,
            ];
        }

        return collect([...array_keys(Permission::SECTIONS), 'other'])
            ->filter(fn (string $key) => isset($sections[$key]))
            ->map(fn (string $key) => ['key' => $key, 'label' => __('settings.roles.sections.'.$key), 'modules' => $sections[$key]])
            ->values()
            ->all();
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

        $this->addRequiredViews();

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
        unset($this->roles, $this->selectedRole);
        $this->selectRole($role->id);
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
        // The answer (or why not) shows on that role's page.
        $this->selectRole($id);

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

        if ($action === 'leave') {
            $this->leaveTo($id);
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
        unset($this->grants[$id], $this->roles, $this->selectedRole);
        $this->selectRole('');
        Notify::success(__('settings.roles.deleted', ['name' => $role->name]), on: $this);
    }

    public function render(): View
    {
        $permissions = $this->modules->flatten();

        return view('livewire.settings.roles', [
            'allPermissionIds' => $permissions->map(fn (Permission $permission) => (string) $permission->id)->values()->all(),
            'requiredViews' => Permission::requiredViews($permissions),
            'changedRoleIds' => array_map('strval', array_keys($this->changedGrants())),
        ])->title(__('settings.tabs.roles'));
    }

    /**
     * A chosen id if it is one of the roles, otherwise the first role.
     */
    private function validRoleId(mixed $id): string
    {
        if (Ids::valid($id) && $this->roles->contains('id', (string) $id)) {
            return (string) $id;
        }

        return (string) $this->roles->first()?->id;
    }

    /**
     * Ticking "Confirm draft receipts" without "See draft receipts" makes no
     * sense: the module's "see" permission is added (the screen already does it).
     */
    private function addRequiredViews(): void
    {
        $required = Permission::requiredViews(Permission::all());

        foreach ($this->grants as $roleId => $ids) {
            $ids = array_map('strval', $ids);

            foreach ($ids as $id) {
                if (isset($required[$id]) && ! in_array($required[$id], $ids, true)) {
                    $ids[] = $required[$id];
                }
            }

            $this->grants[$roleId] = array_values(array_unique($ids));
        }
    }

    /**
     * Roles whose ticked boxes differ from what is saved.
     *
     * @return array<string, list<string>> role id => granted ids (sorted)
     */
    private function changedGrants(): array
    {
        $current = $this->currentGrants();
        $changed = [];

        foreach ($this->grants as $roleId => $ids) {
            $after = collect($ids)->map(fn ($id) => (string) $id)->unique()->sort()->values()->all();
            $before = collect($current[$roleId] ?? [])->map(fn ($id) => (string) $id)->sort()->values()->all();

            if ($after !== $before) {
                $changed[(string) $roleId] = $after;
            }
        }

        return $changed;
    }

    /**
     * "Changed" = differs from the saved permissions, so adding or deleting a
     * role, or saving, never leaves a change behind.
     *
     * @return array<string, mixed>
     */
    protected function formState(): array
    {
        return $this->changedGrants();
    }

    /**
     * @return array{title: string, body: string, button: string}
     */
    protected function leaveQuestion(): array
    {
        return [
            'title' => __('confirm.discard.roles.title'),
            'body' => __('confirm.discard.roles.body'),
            'button' => __('confirm.discard.roles.button'),
        ];
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
