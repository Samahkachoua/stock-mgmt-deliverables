<x-settings.layout>
    <div class="flex flex-col gap-4">
        <x-master-data.header
            :heading="__('settings.warehouses.heading')"
            :description="__('settings.warehouses.description')"
            :add-label="__('settings.warehouses.add')"
            :editing="$editing"
        />

        @if ($editing !== null)
            <x-master-data.editor :heading="$editing === '' ? __('settings.warehouses.new_heading') : __('settings.warehouses.edit_heading', ['name' => $form['name']])">
                <x-form.input model="form.name" :label="__('settings.warehouses.name')" required autofocus />
                <x-form.input model="form.code" :label="__('settings.warehouses.code')" :hint="__('settings.warehouses.code_hint')" ltr required class="uppercase tabular" />
                <x-form.input model="form.city" :label="__('settings.warehouses.city')" required />
                <x-form.input model="form.address" :label="__('settings.warehouses.address')" />
                <x-slot:after>
                    <x-form.toggle class="mt-4" model="form.active" :label="__('settings.warehouses.in_use')" :hint="__('settings.warehouses.in_use_hint')" />
                </x-slot:after>
            </x-master-data.editor>
        @endif

        <x-filters-bar :search-placeholder="__('settings.warehouses.search')" />

        <x-data-table
            :columns="[
                'name' => ['label' => __('settings.warehouses.name_and_code'), 'card' => 'title'],
                'city' => ['label' => __('settings.warehouses.city')],
                'address' => ['label' => __('settings.warehouses.address'), 'priority' => 2],
                'status' => ['label' => __('settings.status'), 'card' => 'badge'],
            ]"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            loading-target="search, resetFilters, gotoPage, nextPage, previousPage, loadRows, save"
            :empty-title="$search !== '' ? __('transactions.list.no_matches_title') : __('settings.warehouses.empty_title')"
            :empty-body="$search !== '' ? __('transactions.list.no_matches_body') : __('settings.warehouses.empty')"
        >
            <x-slot:empty-action>
                @if ($search !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @else
                    <x-empty-action action="add" icon="add" :label="__('settings.warehouses.add')" primary />
                @endif
            </x-slot:empty-action>
            @foreach ($rows as $warehouse)
                <x-data-table.row wire:key="warehouse-{{ $warehouse->id }}" :action="'edit(\''.$warehouse->id.'\')'" :label="__('settings.edit').' '.$warehouse->name">
                    <x-data-table.cell col="name">
                        <div class="flex flex-wrap items-center gap-2">
                            <bdi class="font-semibold text-fg">{{ $warehouse->name }}</bdi>
                            <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $warehouse->code }}</span>
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell col="city" class="text-fg">{{ $warehouse->city }}</x-data-table.cell>
                    <x-data-table.cell col="address" class="text-muted">{{ $warehouse->address }}</x-data-table.cell>
                    <x-data-table.cell col="status"><x-master-data.active-badge :active="$warehouse->active" /></x-data-table.cell>
                </x-data-table.row>
            @endforeach
        </x-data-table>
    </div>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
