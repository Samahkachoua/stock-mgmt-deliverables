<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
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
 * may work in (user_warehouse). A customer's own staff are linked to that
 * customer (app_user.customer_id), and optionally to some of its branches
 * (user_customer_branch). Accounts are switched off, never deleted.
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

    /** Where this person's forms, lists and reports start: one of the ticked warehouses. */
    public string $defaultWarehouseId = '';

    /** The customer whose own staff this person is; '' = the warehouse company's staff. */
    public string $customerId = '';

    /**
     * Branches of that customer this person works for (optional).
     *
     * @var list<string>
     */
    public array $branchIds = [];

    public string $preferred_lang = '';

    public bool $active = true;

    /** "Email about orders": only offered (and only used) with an orders confirm permission. */
    public bool $notifyNewOrders = true;

    /** "Email about draft receipts": the same, with the draft receipts confirm permission. */
    public bool $notifyDraftReceipts = true;

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
        return User::with(['roles', 'warehouses', 'branches'])->orderBy('full_name')->get();
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

    /**
     * @return array<string, string> id => name
     */
    #[Computed]
    public function customers(): array
    {
        return Customer::orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Branches of the chosen customer.
     *
     * @return Collection<int, CustomerBranch>
     */
    #[Computed]
    public function customerBranches(): Collection
    {
        return CustomerBranch::where('customer_id', $this->customerId === '' ? null : $this->customerId)->orderBy('name')->get();
    }

    /**
     * Whether the ticked roles may confirm orders (a draft that takes stock out),
     * so the order email setting applies.
     */
    #[Computed]
    public function confirmsOrders(): bool
    {
        return $this->confirmsDrafts(fn (DraftUi $kind) => ! $kind->receives());
    }

    /**
     * Whether the ticked roles may confirm draft receipts (a draft that brings
     * stock in), so the draft receipt email setting applies.
     */
    #[Computed]
    public function confirmsDraftReceipts(): bool
    {
        return $this->confirmsDrafts(fn (DraftUi $kind) => $kind->receives());
    }

    /**
     * @param  callable(DraftUi): bool  $which  the kinds of draft to look at
     */
    private function confirmsDrafts(callable $which): bool
    {
        $byRole = Permissions::byRole();
        $granted = collect($this->roleIds)->flatMap(fn ($roleId) => $byRole[(string) $roleId] ?? []);

        return collect(DraftTypes::all())
            ->filter($which)
            ->contains(fn (DraftUi $kind) => $granted->contains($kind->confirmPermission()));
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

    /**
     * Whether the ticked roles could confirm orders / draft receipts before a
     * role change (set in updating(), read in updated() of the same request).
     *
     * @var array{0: bool, 1: bool}|null
     */
    private ?array $couldConfirm = null;

    public function updating(string $property): void
    {
        if (str_starts_with($property, 'roleIds')) {
            $this->couldConfirm = [$this->confirmsOrders(), $this->confirmsDraftReceipts()];
        }
    }

    public function updated(string $property): void
    {
        // The email settings follow the roles: ticked when a role that may
        // confirm those drafts is added, off when no role may any more.
        if (str_starts_with($property, 'roleIds')) {
            unset($this->confirmsOrders, $this->confirmsDraftReceipts);
            [$couldOrders, $couldReceipts] = $this->couldConfirm ?? [false, false];
            $this->notifyNewOrders = $this->confirmsOrders() && ($couldOrders ? $this->notifyNewOrders : true);
            $this->notifyDraftReceipts = $this->confirmsDraftReceipts() && ($couldReceipts ? $this->notifyDraftReceipts : true);
        }

        // Branches belong to a customer: another customer, no branches chosen.
        if ($property === 'customerId') {
            $this->branchIds = [];
        }

        // The default warehouse is one of the ticked ones: kept while ticked, the
        // only one when a single warehouse is ticked, else to be chosen again.
        if (str_starts_with($property, 'warehouseIds')) {
            $ticked = array_map('strval', $this->warehouseIds);

            if (count($ticked) === 1) {
                $this->defaultWarehouseId = $ticked[0];
            } elseif (! in_array($this->defaultWarehouseId, $ticked, true)) {
                $this->defaultWarehouseId = '';
            }
        }

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
            $user->customer_id = $data['customerId'] === '' ? null : $data['customerId'];
            $user->default_warehouse_id = $data['defaultWarehouseId'];
            // A hidden setting (the roles may not confirm those drafts): off for a new
            // user, kept as it was for an existing one. open() and a role change
            // decide what the box shows when it is offered again.
            $user->notify_new_orders = $this->confirmsOrders() ? $data['notifyNewOrders'] : ($isNew ? false : $user->notify_new_orders);
            $user->notify_draft_receipts = $this->confirmsDraftReceipts() ? $data['notifyDraftReceipts'] : ($isNew ? false : $user->notify_draft_receipts);

            if ($data['password'] !== '') {
                $user->password_hash = $data['password']; // hashed by the model cast
            }

            $user->save();
            $user->roles()->sync($data['roleIds']);
            $user->warehouses()->sync($data['warehouseIds']);
            // After the customer is saved: a branch must be of the user's customer (trg_check_user_branch).
            $user->branches()->sync($user->customer_id === null ? [] : $data['branchIds']);

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
            'defaultWarehouseId' => ['required', Rule::in(array_map('strval', $this->warehouseIds))],
            'customerId' => ['nullable', 'integer', 'exists:customer,id'],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer', Rule::exists('customer_branch', 'id')->where('customer_id', $this->customerId === '' ? null : $this->customerId)],
            'active' => ['boolean', $this->staySignedIn($editingSelf)],
            'notifyNewOrders' => ['boolean'],
            'notifyDraftReceipts' => ['boolean'],
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
            'defaultWarehouseId.required' => __('settings.users.errors.default_warehouse_required'),
            'defaultWarehouseId.in' => __('settings.users.errors.default_warehouse_required'),
            'customerId.integer' => __('settings.users.errors.customer_invalid'),
            'customerId.exists' => __('settings.users.errors.customer_invalid'),
            'branchIds.*.integer' => __('settings.users.errors.branch_invalid'),
            'branchIds.*.exists' => __('settings.users.errors.branch_invalid'),
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
            'notify_new_orders' => (bool) $user->notify_new_orders,
            'notify_draft_receipts' => (bool) $user->notify_draft_receipts,
            'roles' => $user->roles()->orderBy('code')->pluck('code')->all(),
            'warehouses' => $user->warehouses()->orderBy('code')->pluck('code')->all(),
            // The warehouse they actually start in (a user saved before defaults existed has none stored).
            'default_warehouse' => Warehouse::whereKey($user->fresh()?->defaultWarehouseId())->value('code'),
            'customer_id' => $user->customer_id,
            'branches' => $user->branches()->orderBy('code')->pluck('code')->all(),
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
        unset($this->confirmsOrders, $this->confirmsDraftReceipts);
        // Shown as saved, and off for someone who may not confirm them (a new user has no roles yet).
        $this->notifyNewOrders = ($user->notify_new_orders ?? true) && $this->confirmsOrders();
        $this->notifyDraftReceipts = ($user->notify_draft_receipts ?? true) && $this->confirmsDraftReceipts();
        $this->warehouseIds = $user->exists ? $user->warehouses()->pluck('warehouse.id')->all() : [];
        $this->defaultWarehouseId = $user->exists ? (string) $user->defaultWarehouseId() : '';
        $this->customerId = (string) $user->customer_id;
        $this->branchIds = $user->exists ? $user->branches()->pluck('customer_branch.id')->all() : [];
    }
}
