<x-settings.layout>
    <div class="flex flex-col gap-4">
        <x-master-data.header
            :heading="__('settings.customers.heading')"
            :description="__('settings.customers.description')"
            :add-label="__('settings.customers.add')"
            :editing="$editing"
        />

        @if ($editing !== null)
            <x-master-data.editor :heading="$editing === '' ? __('settings.customers.new_heading') : __('settings.customers.edit_heading', ['name' => $form['name']])">
                <x-form.input model="form.name" :label="__('settings.customers.name')" required autofocus />
                <x-form.input model="form.code" :label="__('settings.customers.code')" :hint="__('settings.customers.code_hint')" ltr required class="uppercase tabular" />
                <x-form.input model="form.contact_email" type="email" :label="__('settings.customers.email')" ltr autocomplete="off" />
                <x-form.input model="form.contact_phone" type="tel" :label="__('settings.customers.phone')" ltr autocomplete="off" />
                <x-slot:after>
                    <x-form.toggle class="mt-4" model="form.active" :label="__('settings.customers.in_use')" :hint="__('settings.customers.in_use_hint')" />
                </x-slot:after>
            </x-master-data.editor>
        @endif

        <x-filters-bar :search-placeholder="__('settings.customers.search')" />

        <x-data-table
            :columns="[
                'name' => ['label' => __('settings.customers.name_and_code'), 'card' => 'title'],
                'email' => ['label' => __('settings.customers.email'), 'priority' => 2],
                'phone' => ['label' => __('settings.customers.phone')],
                'status' => ['label' => __('settings.status'), 'card' => 'badge'],
            ]"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            loading-target="search, resetFilters, gotoPage, nextPage, previousPage, loadRows, save"
            :empty-title="$search !== '' ? __('transactions.list.no_matches_title') : __('settings.customers.empty_title')"
            :empty-body="$search !== '' ? __('transactions.list.no_matches_body') : __('settings.customers.empty')"
        >
            <x-slot:empty-action>
                @if ($search !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @else
                    <x-empty-action action="add" icon="add" :label="__('settings.customers.add')" primary />
                @endif
            </x-slot:empty-action>
            @foreach ($rows as $customer)
                <x-data-table.row wire:key="customer-{{ $customer->id }}" :action="'edit(\''.$customer->id.'\')'" :label="__('settings.edit').' '.$customer->name">
                    <x-data-table.cell col="name">
                        <div class="flex flex-wrap items-center gap-2">
                            <bdi class="font-semibold text-fg">{{ $customer->name }}</bdi>
                            <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $customer->code }}</span>
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell col="email" class="text-fg"><span dir="ltr">{{ $customer->contact_email }}</span></x-data-table.cell>
                    <x-data-table.cell col="phone" class="text-fg"><span dir="ltr" class="tabular">{{ $customer->contact_phone }}</span></x-data-table.cell>
                    <x-data-table.cell col="status"><x-master-data.active-badge :active="$customer->active" /></x-data-table.cell>
                </x-data-table.row>
            @endforeach
        </x-data-table>
    </div>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
