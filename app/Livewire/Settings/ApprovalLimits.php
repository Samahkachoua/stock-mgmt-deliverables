<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\Customer;
use App\Models\TransactionType;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings › Approval limits, per transaction type that supports one
 * (transaction_type.supports_approval_threshold): the GLOBAL approval_threshold,
 * and CUSTOMER overrides. An item's own limit is set on the item form.
 * fn_resolve_threshold uses item → customer → global; an empty field means
 * "no limit here" (no row), so the next level applies, and with none at all
 * nothing needs approval.
 *
 * @property-read Collection<int, TransactionType> $types
 * @property-read array<string, string> $customers
 */
#[Layout('components.layouts.app')]
class ApprovalLimits extends Component
{
    use ConfirmsActions;

    /** @var array<string, string> transaction type code => quantity in base units ('' = none) */
    public array $limits = [];

    /** @var array<string, array<string, string>> customer id => type code => quantity ('' = use the global limit) */
    public array $customerLimits = [];

    /** Customer chosen in the "Add a customer" dropdown. */
    public string $newCustomer = '';

    public function mount(): void
    {
        Gate::authorize('admin.settings.manage');

        $rows = DB::table('approval_threshold')
            ->whereIn('scope', ['GLOBAL', 'CUSTOMER'])
            ->whereIn('transaction_type', $this->types->pluck('code'))
            ->get(['scope', 'scope_id', 'transaction_type', 'qty_base']);

        foreach ($this->types as $type) {
            $global = $rows->first(fn ($row) => $row->scope === 'GLOBAL' && $row->transaction_type === $type->code);
            $this->limits[$type->code] = $global ? $this->format($global->qty_base) : '';
        }

        $customerIds = Customer::whereIn('id', $rows->where('scope', 'CUSTOMER')->pluck('scope_id'))->orderBy('name')->pluck('id');

        foreach ($customerIds as $customerId) {
            foreach ($this->types as $type) {
                $row = $rows->first(fn ($row) => $row->scope === 'CUSTOMER' && $row->scope_id === $customerId && $row->transaction_type === $type->code);
                $this->customerLimits[$customerId][$type->code] = $row ? $this->format($row->qty_base) : '';
            }
        }
    }

    /**
     * @return Collection<int, TransactionType>
     */
    #[Computed]
    public function types(): Collection
    {
        return TransactionType::where('supports_approval_threshold', true)
            ->where('active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Every customer, id => name (the overrides table shows their names).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function customers(): array
    {
        return Customer::orderBy('name')->pluck('name', 'id')->all();
    }

    public function updated(string $property): void
    {
        if ($property === 'newCustomer') {
            $this->addCustomer();

            return;
        }

        $this->validateOnly($property);
    }

    /**
     * Adds a row for the customer chosen in the dropdown, its limits empty
     * (= the global limit) until filled in.
     */
    public function addCustomer(): void
    {
        $id = $this->newCustomer;
        $this->newCustomer = '';

        if (! array_key_exists($id, $this->customers) || isset($this->customerLimits[$id])) {
            return;
        }

        $this->customerLimits[$id] = $this->types->mapWithKeys(fn (TransactionType $type) => [$type->code => ''])->all();
    }

    /**
     * The bin on a customer's row. Limits that are saved are deleted only after
     * asking; a row added on this page and not saved yet just goes.
     */
    public function removeCustomer(string $id): void
    {
        Gate::authorize('admin.settings.manage');

        if (! DB::table('approval_threshold')->where('scope', 'CUSTOMER')->where('scope_id', $id)->exists()) {
            $this->dropCustomerRow($id);

            return;
        }

        $name = $this->customers[$id] ?? '';

        $this->askToConfirm(
            'deleteLimits', $id,
            __('confirm.delete.limits.title', ['name' => $name]),
            __('confirm.delete.limits.body', ['name' => $name]),
            __('confirm.delete.limits.button'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action !== 'deleteLimits') {
            return;
        }

        Gate::authorize('admin.settings.manage');

        DB::table('approval_threshold')->where('scope', 'CUSTOMER')->where('scope_id', $id)->delete();
        Notify::success(__('confirm.delete.limits.done', ['name' => $this->customers[$id] ?? '']), on: $this);
        $this->dropCustomerRow($id);
    }

    private function dropCustomerRow(string $id): void
    {
        unset($this->customerLimits[$id]);
        $this->resetValidation('customerLimits.'.$id.'.*');
    }

    public function save(): void
    {
        Gate::authorize('admin.settings.manage');

        $data = $this->validate();
        $codes = $this->types->pluck('code')->all();
        // Only real customers (the keys come from the browser).
        $data['customerLimits'] = array_intersect_key($data['customerLimits'] ?? [], $this->customers);

        DB::transaction(function () use ($data, $codes) {
            // Replace the global and customer limits of these types with what the page shows.
            DB::table('approval_threshold')->whereIn('scope', ['GLOBAL', 'CUSTOMER'])->whereIn('transaction_type', $codes)->delete();

            $rows = [];

            foreach ($data['limits'] ?? [] as $code => $qty) {
                if ($qty !== null && $qty !== '') {
                    $rows[] = ['scope' => 'GLOBAL', 'scope_id' => null, 'transaction_type' => $code, 'qty_base' => $qty];
                }
            }

            foreach ($data['customerLimits'] as $customerId => $limits) {
                foreach ($limits as $code => $qty) {
                    if ($qty !== null && $qty !== '') {
                        $rows[] = ['scope' => 'CUSTOMER', 'scope_id' => $customerId, 'transaction_type' => $code, 'qty_base' => $qty];
                    }
                }
            }

            DB::table('approval_threshold')->insert($rows);
        });

        Notify::success(__('settings.limits.saved'), on: $this);
    }

    public function render(): View
    {
        return view('livewire.settings.approval-limits')->title(__('settings.tabs.approval_limits'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $quantity = ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'];
        $codes = implode(',', $this->types->pluck('code')->all());
        $rules = [
            'customerLimits' => ['array'],
            'customerLimits.*' => ['array:'.$codes],
        ];

        foreach ($this->types as $type) {
            $rules['limits.'.$type->code] = $quantity;
            $rules['customerLimits.*.'.$type->code] = $quantity;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $messages = [];

        foreach (['limits.*', 'customerLimits.*.*'] as $field) {
            $messages[$field.'.numeric'] = __('settings.limits.errors.number');
            $messages[$field.'.min'] = __('settings.limits.errors.number');
            $messages[$field.'.max'] = __('settings.limits.errors.too_large');
            $messages[$field.'.decimal'] = __('settings.limits.errors.decimals');
        }

        return $messages;
    }

    /**
     * NUMERIC(18,4) comes back as "10.0000"; show "10".
     */
    private function format(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}
