<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\Permissions;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings › Users: accounts, their roles (user_role) and the warehouses they
 * may work in (user_warehouse). Accounts are switched off, never deleted.
 *
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Warehouse> $allWarehouses
 */
#[Layout('components.layouts.app')]
class Users extends Component
{
    use ConfirmsActions;

    /** null = form closed, '' = adding, otherwise the id being edited (set by add() / edit()). */
    #[Locked]
    public ?string $editing = null;

    public string $full_name = '';

    public string $email = '';

    public string $password = '';

    /** @var list<string> */
    public array $roleIds = [];

    /** @var list<string> */
    public array $warehouseIds = [];

    public string $preferred_lang = '';

    public bool $active = true;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::with(['roles', 'warehouses'])->orderBy('full_name')->get();
    }

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return Role::orderBy('name')->get();
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function allWarehouses(): Collection
    {
        return Warehouse::orderBy('name')->get();
    }

    public function add(): void
    {
        $this->authorize('create', User::class);

        $this->open('', new User(['active' => true]));
    }

    public function edit(string $id): void
    {
        $user = User::findOrFail($id);
        $this->authorize('update', $user);

        $this->open($id, $user);
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if ($this->editing !== null) {
            $this->validateOnly(str($property)->before('.')->toString());
        }
    }

    public function save(): void
    {
        $this->store(deactivationConfirmed: false);
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action === 'deactivate' && $this->editing === $id) {
            $this->store(deactivationConfirmed: true);
        }
    }

    /**
     * Saves the form; taking away a user's sign-in asks first (CLAUDE.md "Confirmations").
     */
    private function store(bool $deactivationConfirmed): void
    {
        if ($this->editing === null) {
            return;
        }

        $user = $this->editing === '' ? new User : User::findOrFail($this->editing);
        $this->authorize($user->exists ? 'update' : 'create', $user->exists ? $user : User::class);

        $this->email = trim($this->email);
        $data = $this->validate();
        $isNew = ! $user->exists;

        if (! $deactivationConfirmed && ! $isNew && $user->active && ! $data['active']) {
            $this->askToConfirm(
                'deactivate', (string) $user->id,
                __('confirm.deactivate.user.title', ['name' => $user->full_name]),
                __('confirm.deactivate.user.body', ['name' => $user->full_name]),
                __('confirm.deactivate.user.button'),
            );

            return;
        }

        DB::transaction(function () use ($user, $data, $isNew) {
            $before = $isNew ? null : $this->snapshot($user);

            $user->full_name = trim($data['full_name']);
            $user->email = $data['email'];
            $user->preferred_lang = $data['preferred_lang'];
            $user->active = $data['active'];

            if ($data['password'] !== '') {
                $user->password_hash = $data['password']; // hashed by the model cast
            }

            $user->save();
            $user->roles()->sync($data['roleIds']);
            $user->warehouses()->sync($data['warehouseIds']);

            $after = $this->snapshot($user);

            // Never the password itself: only that an administrator set a new one.
            if ($data['password'] !== '') {
                $after['password_changed'] = true;
            }

            if ($before !== $after) {
                Audit::record('app_user', $user->id, $isNew ? 'CREATE' : 'UPDATE', $before, $after);
            }
        });

        $this->editing = null;
        $this->password = '';
        Notify::success(__($isNew ? 'settings.users.added' : 'settings.users.updated', ['name' => $user->full_name]), on: $this);
        unset($this->users);
    }

    public function render(): View
    {
        return view('livewire.settings.users')->title(__('settings.tabs.users'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $editingSelf = $this->editing !== null && $this->editing === Auth::id();

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', $this->uniqueEmail(...)],
            'password' => [$this->editing === '' ? 'required' : 'nullable', 'string', 'min:8'],
            'preferred_lang' => ['required', Rule::in(array_keys(config('ui.locales')))],
            'roleIds' => ['required', 'array', 'min:1', $this->keepManagingUsers($editingSelf)],
            'roleIds.*' => ['integer', 'exists:role,id'],
            'warehouseIds' => ['required', 'array', 'min:1'],
            'warehouseIds.*' => ['integer', 'exists:warehouse,id'],
            'active' => ['boolean', $this->staySignedIn($editingSelf)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'full_name.required' => __('settings.users.errors.name_required'),
            'full_name.max' => __('settings.errors.too_long', ['max' => 150]),
            'email.required' => __('settings.users.errors.email_required'),
            'email.email' => __('settings.users.errors.email_invalid'),
            'email.max' => __('settings.errors.too_long', ['max' => 255]),
            'password.required' => __('settings.users.errors.password_required'),
            'password.min' => __('settings.users.errors.password_short', ['min' => 8]),
            'preferred_lang.required' => __('settings.users.errors.language_required'),
            'preferred_lang.in' => __('settings.users.errors.language_required'),
            'roleIds.required' => __('settings.users.errors.role_required'),
            'roleIds.min' => __('settings.users.errors.role_required'),
            'warehouseIds.required' => __('settings.users.errors.warehouse_required'),
            'warehouseIds.min' => __('settings.users.errors.warehouse_required'),
        ];
    }

    /**
     * Emails are unique case-insensitively (uq_app_user_email on lower(email)).
     */
    private function uniqueEmail(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = User::whereRaw('lower(email) = lower(?)', [$value])
            ->when($this->editing, fn ($query, string $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail(__('settings.users.errors.email_taken'));
        }
    }

    /**
     * Administrators can't take away their own ability to manage users. Checked
     * by permission, not by role name, so it holds for any role set-up.
     */
    private function keepManagingUsers(bool $editingSelf): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($editingSelf) {
            if (! $editingSelf) {
                return;
            }

            $byRole = Permissions::byRole();
            $granted = collect((array) $value)->flatMap(fn ($roleId) => $byRole[$roleId] ?? []);

            if (! $granted->contains('admin.users.manage')) {
                $fail(__('settings.users.errors.keep_own_admin'));
            }
        };
    }

    private function staySignedIn(bool $editingSelf): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($editingSelf) {
            if ($editingSelf && ! $value) {
                $fail(__('settings.users.errors.keep_own_active'));
            }
        };
    }

    /**
     * What the audit log stores about an account (no secrets).
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'full_name' => $user->full_name,
            'email' => $user->email,
            'preferred_lang' => $user->preferred_lang,
            'active' => (bool) $user->active,
            'roles' => $user->roles()->orderBy('code')->pluck('code')->all(),
            'warehouses' => $user->warehouses()->orderBy('code')->pluck('code')->all(),
        ];
    }

    private function open(string $editing, User $user): void
    {
        $this->resetValidation();
        $this->editing = $editing;
        $this->full_name = (string) $user->full_name;
        $this->email = (string) $user->email;
        $this->password = '';
        $this->preferred_lang = $user->preferred_lang ?? app()->getLocale();
        $this->active = $user->active ?? true;
        $this->roleIds = $user->exists ? $user->roles()->pluck('role.id')->all() : [];
        $this->warehouseIds = $user->exists ? $user->warehouses()->pluck('warehouse.id')->all() : [];
    }
}
