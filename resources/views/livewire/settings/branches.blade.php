<x-settings.layout>
    <div class="flex flex-col gap-4">
        <x-master-data.header
            :heading="__('settings.branches.heading')"
            :description="__('settings.branches.description')"
            :add-label="__('settings.branches.add')"
            :editing="$editing"
        />

        @if ($editing !== null)
            <x-master-data.editor :heading="$editing === '' ? __('settings.branches.new_heading') : __('settings.branches.edit_heading', ['name' => $form['name']])">
                {{-- A branch stays with the customer it was created for. --}}
                <x-form.select
                    model="form.customer_id"
                    :label="__('settings.branches.customer')"
                    :options="['' => __('settings.branches.choose_customer')] + $customers"
                    :hint="$editing === '' ? null : __('settings.branches.customer_fixed_hint')"
                    :disabled="$editing !== ''"
                    required
                />
                <x-form.input model="form.name" :label="__('settings.branches.name')" required autofocus />
                <x-form.input model="form.code" :label="__('settings.branches.code')" :hint="__('settings.branches.code_hint')" ltr required class="uppercase tabular" />
                <x-form.input model="form.manager_email" type="email" :label="__('settings.branches.manager_email')" :hint="__('settings.branches.manager_email_hint')" ltr required autocomplete="email" maxlength="320" />
                <x-form.input model="form.address" :label="__('settings.branches.address')" autocomplete="off" />
                <x-slot:after>
                    <x-form.toggle class="mt-4" model="form.active" :label="__('settings.branches.in_use')" :hint="__('settings.branches.in_use_hint')" />
                </x-slot:after>
            </x-master-data.editor>
        @endif

        <x-filters-bar :search-placeholder="__('settings.branches.search')" />

        <x-data-table
            :columns="[
                'name' => ['label' => __('settings.branches.name_and_code'), 'card' => 'title'],
                'customer' => ['label' => __('settings.branches.customer'), 'card' => 'lead'],
                'manager_email' => ['label' => __('settings.branches.manager_email'), 'priority' => 2],
                'address' => ['label' => __('settings.branches.address'), 'priority' => 2],
                'status' => ['label' => __('settings.status'), 'card' => 'badge'],
            ]"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            loading-target="search, resetFilters, gotoPage, nextPage, previousPage, loadRows, save"
            :empty-title="$search !== '' ? __('transactions.list.no_matches_title') : __('settings.branches.empty_title')"
            :empty-body="$search !== '' ? __('transactions.list.no_matches_body') : __('settings.branches.empty')"
        >
            <x-slot:empty-action>
                @if ($search !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @else
                    <x-empty-action action="add" icon="add" :label="__('settings.branches.add')" primary />
                @endif
            </x-slot:empty-action>
            @foreach ($rows as $branch)
                <x-data-table.row wire:key="branch-{{ $branch->id }}" :action="'edit(\''.$branch->id.'\')'" :label="__('settings.edit').' '.$branch->name">
                    <x-data-table.cell col="name">
                        <div class="flex flex-wrap items-center gap-2">
                            <bdi class="font-semibold text-fg">{{ $branch->name }}</bdi>
                            <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $branch->code }}</span>
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell col="customer" class="text-fg"><bdi>{{ $branch->customer?->name }}</bdi></x-data-table.cell>
                    <x-data-table.cell col="manager_email" class="text-fg">
                        @if (filled($branch->manager_email))
                            <span dir="ltr" class="break-all">{{ $branch->manager_email }}</span>
                        @else
                            {{-- Branches from before the field: nothing yet. --}}
                            <span class="text-muted">{{ __('settings.branches.no_manager_email') }}</span>
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell col="address" class="text-fg"><bdi>{{ $branch->address }}</bdi></x-data-table.cell>
                    <x-data-table.cell col="status"><x-master-data.active-badge :active="$branch->active" /></x-data-table.cell>
                </x-data-table.row>
            @endforeach
        </x-data-table>
    </div>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
