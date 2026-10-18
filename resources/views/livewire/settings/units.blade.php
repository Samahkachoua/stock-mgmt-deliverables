@use('App\Support\Format')

<x-settings.layout>
    <div class="flex flex-col gap-4">
        <x-master-data.header
            :heading="__('settings.units.heading')"
            :description="__('settings.units.description')"
            :add-label="__('settings.units.add')"
            :editing="$editing"
        />

        @if ($editing !== null)
            <x-master-data.editor :heading="$editing === '' ? __('settings.units.new_heading') : __('settings.units.edit_heading', ['name' => $form['name']])">
                <x-form.input model="form.name" :label="__('settings.units.name')" :hint="__('settings.units.name_hint')" required autofocus />
                <x-form.input model="form.code" :label="__('settings.units.code')" :hint="__('settings.units.code_hint')" ltr required class="uppercase tabular" />
            </x-master-data.editor>
        @endif

        <x-filters-bar :search-placeholder="__('settings.units.search')" />

        <x-data-table
            :columns="[
                'name' => ['label' => __('settings.units.name_and_code'), 'card' => 'title'],
                'used_by' => ['label' => __('settings.units.used_by'), 'align' => 'end'],
                'delete' => ['label' => ''],
            ]"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            loading-target="search, resetFilters, gotoPage, nextPage, previousPage, loadRows, save"
            :empty-title="$search !== '' ? __('transactions.list.no_matches_title') : __('settings.units.empty_title')"
            :empty-body="$search !== '' ? __('transactions.list.no_matches_body') : __('settings.units.empty')"
        >
            <x-slot:empty-action>
                @if ($search !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @else
                    <x-empty-action action="add" icon="add" :label="__('settings.units.add')" primary />
                @endif
            </x-slot:empty-action>
            @foreach ($rows as $unit)
                <x-data-table.row wire:key="unit-{{ $unit->id }}" :action="'edit(\''.$unit->id.'\')'" :label="__('settings.edit').' '.$unit->label()">
                    <x-data-table.cell col="name">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-fg">{{ $unit->label() }}</span>
                            <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $unit->code }}</span>
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell col="used_by" align="end" class="text-fg tabular">
                        {{ trans_choice('settings.units.item_count', (int) $unit->items_count, ['count' => Format::qty($unit->items_count)]) }}
                    </x-data-table.cell>
                    <x-data-table.cell col="delete" align="end" class="relative z-10">
                        {{-- Above the row's stretched edit button, so it gets its own clicks. --}}
                        <button
                            type="button"
                            wire:click="askDelete('{{ $unit->id }}')"
                            class="inline-flex h-control items-center gap-1.5 rounded-lg px-3 text-body font-semibold text-danger hover:bg-danger-soft"
                        >
                            <x-busy-icon name="delete" target="askDelete('{{ $unit->id }}')" class="size-4" />
                            {{ __('settings.units.delete') }}
                            <span class="sr-only">{{ $unit->label() }}</span>
                        </button>
                        @error('delete.'.$unit->id)
                            <p class="mt-1 text-start text-body text-danger" role="alert">{{ $message }}</p>
                        @enderror
                    </x-data-table.cell>
                </x-data-table.row>
            @endforeach
        </x-data-table>
    </div>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
