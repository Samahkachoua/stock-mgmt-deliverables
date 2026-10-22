<?php

namespace App\Livewire\Settings;

use App\Models\Customer;
use App\Models\CustomerBranch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Settings › Branches: a customer's branches (shops, sites), named on draft
 * receipts and issues. Taken out of use, never deleted; a branch stays with the
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
        return ['customer_id' => '', 'code' => '', 'name' => '', 'address' => '', 'active' => true];
    }

    protected function rules(): array
    {
        return [
            'form.customer_id' => ['required', 'integer', 'exists:customer,id'],
            'form.code' => ['required', 'string', 'max:20', Rule::unique('customer_branch', 'code')
                ->where('customer_id', $this->form['customer_id'] ?? null)
                ->ignore($this->editing ?: null)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.address' => ['nullable', 'string', 'max:500'],
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
            'form.name.required' => __('settings.branches.errors.name_required'),
            'form.name.max' => __('settings.errors.too_long', ['max' => 150]),
            'form.address.max' => __('settings.errors.too_long', ['max' => 500]),
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
        return $query->with('customer')
            ->join('customer', 'customer.id', '=', 'customer_branch.customer_id')
            ->orderBy('customer.name')
            ->orderBy('customer_branch.name')
            ->select('customer_branch.*')
            ->paginate(self::PER_PAGE);
    }

    protected function formFrom(Model $record): array
    {
        return ['customer_id' => (string) $record->getAttribute('customer_id')] + parent::formFrom($record);
    }

    public function render(): View
    {
        return parent::render()->with('customers', Customer::orderBy('name')->pluck('name', 'id')->all());
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
