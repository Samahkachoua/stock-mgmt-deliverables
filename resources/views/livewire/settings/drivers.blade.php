@use('App\Livewire\Settings\Drivers')
@use('App\Models\Driver')

@php
    // Buttons follow DriverPolicy; the component checks it again on every action.
    $canAdd = auth()->user()->can('create', Driver::class);
    $canEdit = auth()->user()->can('update', new Driver);
    $canDelete = auth()->user()->can('delete', new Driver);
@endphp

<x-settings.layout>
    <div class="flex flex-col gap-section">
        <x-master-data.header
            :heading="__('settings.drivers.heading')"
            :description="__('settings.drivers.description')"
            :add-label="$canAdd ? __('settings.drivers.add') : null"
            :editing="$editing"
        />

        @if ($editing !== null)
            <x-master-data.editor :heading="$editing === '' ? __('settings.drivers.new_heading') : __('settings.drivers.edit_heading', ['name' => $form['name']])">
                <x-form.input model="form.name" :label="__('settings.drivers.name')" maxlength="{{ Drivers::MAX['name'] }}" required autofocus />
                <x-form.input model="form.phone" type="tel" :label="__('settings.drivers.phone')" :hint="__('settings.drivers.phone_hint')" maxlength="{{ Drivers::MAX['phone'] }}" ltr required class="tabular" />
                <x-form.input model="form.licence_no" :label="__('settings.drivers.licence_no')" maxlength="{{ Drivers::MAX['licence_no'] }}" ltr required class="uppercase tabular" />
                <x-form.input model="form.national_id" :label="__('settings.drivers.national_id')" maxlength="{{ Drivers::MAX['national_id'] }}" ltr required class="uppercase tabular" />
                <x-form.input model="form.truck_no" :label="__('settings.drivers.truck_no')" :hint="__('settings.drivers.truck_hint')" maxlength="{{ Drivers::MAX['truck_no'] }}" ltr required class="uppercase tabular" />
            </x-master-data.editor>
        @endif

        <x-filters-bar :search-placeholder="__('settings.drivers.search')" />

        <x-data-table
            :columns="array_filter([
                'name' => ['label' => __('settings.drivers.name'), 'card' => 'title'],
                'phone' => ['label' => __('settings.drivers.phone')],
                'truck_no' => ['label' => __('settings.drivers.truck_no')],
                'licence_no' => ['label' => __('settings.drivers.licence_no'), 'priority' => 2],
                'national_id' => ['label' => __('settings.drivers.national_id'), 'priority' => 2],
                'delete' => $canDelete ? ['label' => ''] : null,
            ])"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            loading-target="search, resetFilters, gotoPage, nextPage, previousPage, loadRows, save"
            :empty-title="$search !== '' ? __('transactions.list.no_matches_title') : __('settings.drivers.empty_title')"
            :empty-body="$search !== '' ? __('transactions.list.no_matches_body') : ($canAdd ? __('settings.drivers.empty') : __('settings.drivers.empty_read_only'))"
        >
            <x-slot:empty-action>
                @if ($search !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @elseif ($canAdd)
                    <x-empty-action action="add" icon="add" :label="__('settings.drivers.add')" primary />
                @endif
            </x-slot:empty-action>
            @foreach ($rows as $driver)
                <x-data-table.row wire:key="driver-{{ $driver->id }}" :action="$canEdit ? 'edit(\''.$driver->id.'\')' : null" :label="__('settings.edit').' '.$driver->name">
                    <x-data-table.cell col="name">
                        <span class="font-semibold text-fg">{{ $driver->name }}</span>
                    </x-data-table.cell>
                    <x-data-table.cell col="phone" class="text-fg tabular">
                        <span dir="ltr">{{ $driver->phone }}</span>
                    </x-data-table.cell>
                    <x-data-table.cell col="truck_no" class="text-fg tabular">
                        <span dir="ltr">{{ $driver->truck_no }}</span>
                    </x-data-table.cell>
                    <x-data-table.cell col="licence_no" class="text-fg tabular">
                        <span dir="ltr">{{ $driver->licence_no }}</span>
                    </x-data-table.cell>
                    <x-data-table.cell col="national_id" class="text-fg tabular">
                        <span dir="ltr">{{ $driver->national_id }}</span>
                    </x-data-table.cell>
                    @if ($canDelete)
                        <x-data-table.cell col="delete" align="end" class="relative z-10">
                            {{-- Above the row's stretched edit button, so it gets its own clicks. --}}
                            <button
                                type="button"
                                wire:click="askDelete('{{ $driver->id }}')"
                                data-bs-toggle="tooltip"
                                data-bs-title="{{ __('settings.drivers.delete') }}"
                                class="inline-grid size-control place-items-center rounded-lg text-danger hover:bg-danger-soft"
                            >
                                <x-busy-icon name="delete" target="askDelete('{{ $driver->id }}')" class="size-4" />
                                <span class="sr-only">{{ __('settings.drivers.delete') }} {{ $driver->name }}</span>
                            </button>
                            @error('delete.'.$driver->id)
                                <p class="mt-1 text-start text-body text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </x-data-table.cell>
                    @endif
                </x-data-table.row>
            @endforeach
        </x-data-table>
    </div>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
