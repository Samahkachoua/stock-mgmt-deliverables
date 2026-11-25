<?php

namespace App\Livewire\Settings;

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Settings › Branches: a customer's branches (shops, sites), named on draft
 * receipts and orders, each served by one warehouse (where an Order / Draft
 * receipt for it starts). Taken out of use, never deleted; a branch stays with the
 * customer it was created for (drafts and documents point at both).
 *
 * @extends MasterDataPage<CustomerBranch>
 */
class Branches extends MasterDataPage
{
    protected function model(): string
    {
        return CustomerBranch::class;
    }

    protected function entity(): string
    {
        return 'customer_branch';
    }

    protected function blank(): array
    {
        return ['customer_id' => '', 'code' => '', 'manager_email' => '', 'name' => '', 'address' => '', 'warehouse_id' => '', 'active' => true];
    }

    protected function rules(): array
    {
        return [
            'form.customer_id' => ['required', 'integer', 'exists:customer,id'],
            'form.code' => ['required', 'string', 'max:20', Rule::unique('customer_branch', 'code')
                ->where('customer_id', $this->form['customer_id'] ?? null)
                ->ignore($this->editing ?: null)],
            // One branch per customer per manager email (stored lower case; the database checks it too).
            'form.manager_email' => ['required', 'string', 'max:320', 'email', Rule::unique('customer_branch', 'manager_email')
                ->where('customer_id', $this->form['customer_id'] ?? null)
                ->ignore($this->editing ?: null)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.address' => ['nullable', 'string', 'max:500'],
            'form.warehouse_id' => ['required', 'integer', Rule::exists('warehouse', 'id')->where('active', true)],
            'form.active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.customer_id.required' => __('settings.branches.errors.customer_required'),
            'form.customer_id.integer' => __('settings.branches.errors.customer_required'),
            'form.customer_id.exists' => __('settings.branches.errors.customer_required'),
            'form.code.required' => __('settings.branches.errors.code_required'),
            'form.code.max' => __('settings.errors.too_long', ['max' => 20]),
            'form.code.unique' => __('settings.branches.errors.code_taken'),
            'form.manager_email.required' => __('settings.branches.errors.manager_email_required'),
            'form.manager_email.email' => __('settings.branches.errors.manager_email_invalid'),
            'form.manager_email.max' => __('settings.errors.too_long', ['max' => 320]),
            'form.manager_email.unique' => __('settings.branches.errors.manager_email_taken'),
            'form.name.required' => __('settings.branches.errors.name_required'),
            'form.name.max' => __('settings.errors.too_long', ['max' => 150]),
            'form.address.max' => __('settings.errors.too_long', ['max' => 500]),
            'form.warehouse_id.required' => __('settings.branches.errors.warehouse_required'),
            'form.warehouse_id.integer' => __('settings.branches.errors.warehouse_required'),
            'form.warehouse_id.exists' => __('settings.branches.errors.warehouse_required'),
        ];
    }

    /**
     * @param  CustomerBranch  $record
     */
    protected function check(Model $record, array $data): array
    {
        // Drafts and documents point at the branch and its customer together.
        if ($record->exists && $record->customer_id !== (string) $data['customer_id']) {
            return ['customer_id' => __('settings.branches.errors.customer_fixed')];
        }

        return [];
    }

    /**
     * The manager email is kept trimmed and lower case, so "Rana@Acme.com " and
     * "rana@acme.com" are the same email.
     */
    protected function normalize(array $form): array
    {
        $form = parent::normalize($form);

        if (is_string($form['manager_email'] ?? null)) {
            $form['manager_email'] = mb_strtolower(trim($form['manager_email']));
        }

        return $form;
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(customer_branch.code) like ?', [$like])
            ->orWhereRaw('lower(customer_branch.name) like ?', [$like])
            ->orWhereHas('customer', fn (Builder $customer) => $customer->whereRaw('lower(name) like ?', [$like])));
    }

    protected function paginateRows(Builder $query): LengthAwarePaginator
    {
        return $query->with(['customer', 'warehouse'])
            ->join('customer', 'customer.id', '=', 'customer_branch.customer_id')
            ->orderBy('customer.name')
            ->orderBy('customer_branch.name')
            ->orderBy('customer_branch.id')
            ->select('customer_branch.*')
            ->paginate($this->perPage());
    }

    protected function formFrom(Model $record): array
    {
        return ['customer_id' => (string) $record->getAttribute('customer_id'), 'warehouse_id' => (string) $record->getAttribute('warehouse_id')]
            + parent::formFrom($record);
    }

    public function render(): View
    {
        return parent::render()
            ->with('customers', Customer::orderBy('name')->pluck('name', 'id')->all())
            ->with('warehouses', Warehouse::where('active', true)->orderBy('name')->pluck('name', 'id')->all());
    }

    protected function viewName(): string
    {
        return 'livewire.settings.branches';
    }

    protected function pageTitle(): string
    {
        return __('settings.tabs.branches');
    }

    protected function langPrefix(): string
    {
        return 'settings.branches';
    }
}
