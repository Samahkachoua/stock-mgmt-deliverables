<?php

namespace App\Livewire\Settings;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Settings › Customers: the companies whose stock is kept here. Customers are
 * taken out of use, never deleted (their items and documents point at them).
 *
 * @extends MasterDataPage<Customer>
 */
class Customers extends MasterDataPage
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function entity(): string
    {
        return 'customer';
    }

    protected function blank(): array
    {
        return ['code' => '', 'name' => '', 'contact_email' => '', 'contact_phone' => '', 'active' => true];
    }

    protected function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:20', Rule::unique('customer', 'code')->ignore($this->editing ?: null)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'form.contact_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-.\s]+$/'],
            'form.active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.code.required' => __('settings.customers.errors.code_required'),
            'form.code.max' => __('settings.errors.too_long', ['max' => 20]),
            'form.code.unique' => __('settings.customers.errors.code_taken'),
            'form.name.required' => __('settings.customers.errors.name_required'),
            'form.name.max' => __('settings.errors.too_long', ['max' => 150]),
            'form.contact_email.email' => __('settings.users.errors.email_invalid'),
            'form.contact_email.max' => __('settings.errors.too_long', ['max' => 255]),
            'form.contact_phone.max' => __('settings.errors.too_long', ['max' => 50]),
            'form.contact_phone.regex' => __('settings.customers.errors.phone_invalid'),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(code) like ?', [$like])
            ->orWhereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(contact_email) like ?', [$like]));
    }

    protected function viewName(): string
    {
        return 'livewire.settings.customers';
    }

    protected function pageTitle(): string
    {
        return __('settings.tabs.customers');
    }

    protected function langPrefix(): string
    {
        return 'settings.customers';
    }
}
