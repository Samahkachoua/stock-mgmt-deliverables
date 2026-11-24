@use('App\Support\Format')

@php $filtered = $search !== '' || $customer !== '' || $status !== '' || $storage !== ''; @endphp

<div class="flex flex-col gap-section">
    <x-page-header icon="category" :title="__('items.title')" :subtitle="__('items.subtitle')">
        @can('create', \App\Models\Product::class)
            <x-slot:actions>
                @can('product.import')
                    <a href="{{ route('imports.create', 'items') }}" wire:navigate class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                        <x-icon name="upload" class="text-muted" />
                        {{ __('imports.from_excel') }}
                    </a>
                @endcan
                <a
                    href="{{ route('items.create') }}"
                    wire:navigate
                    class="flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-add-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface"
                >
                    <x-icon name="add" />
                    {{ __('items.new') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    @php
        $columns = [
            'code' => ['label' => __('items.list.code')],
            'name' => ['label' => __('items.list.name'), 'card' => 'title'],
            'customer' => ['label' => __('items.list.customer')],
            'base_unit' => ['label' => __('items.list.base_unit'), 'priority' => 2],
            'tracking' => ['label' => __('items.list.tracking'), 'priority' => 2],
            'storage' => ['label' => __('reports.columns.storage_status'), 'priority' => 2],
            'status' => ['label' => __('settings.status'), 'card' => 'badge'],
        ];
    @endphp

    <x-filters-bar :customers="$customers" :statuses="$statuses" :storages="$storages" :search-placeholder="__('items.search')" :columns="$columns" list="items" exportable />

    <x-data-table
        :columns="$columns"
        :paginator="$items"
        :loading="! $rowsLoaded"
        :empty-title="$filtered ? __('transactions.list.no_matches_title') : __('items.empty_title')"
        :empty-body="$filtered ? __('transactions.list.no_matches_body') : __('items.empty_body')"
    >
        <x-slot:empty-action>
            @if ($filtered)
                <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
            @else
                @can('create', \App\Models\Product::class)
                    <x-empty-action :href="route('items.create')" icon="add" :label="__('items.new')" primary />
                @endcan
                @can('product.import')
                    <x-empty-action :href="route('imports.create', 'items')" icon="upload" :label="__('imports.from_excel')" />
                @endcan
            @endif
        </x-slot:empty-action>
        @foreach ($items as $item)
            @php $converter = $item->converter(); @endphp
            <x-data-table.row wire:key="item-{{ $item->id }}" :href="route('items.edit', $item)" :label="__('items.open', ['name' => $item->name()])">
                <x-data-table.cell col="code"><span dir="ltr" class="tabular font-semibold text-fg">{{ $item->sku }}</span></x-data-table.cell>
                <x-data-table.cell col="name" class="font-semibold text-fg"><bdi>{{ $item->name() }}</bdi></x-data-table.cell>
                <x-data-table.cell col="customer" class="text-fg"><bdi>{{ $item->customer->name }}</bdi></x-data-table.cell>
                <x-data-table.cell col="base_unit" class="text-fg">{{ Format::unit($converter->base()) }}</x-data-table.cell>
                <x-data-table.cell col="tracking">
                    <div class="flex flex-wrap gap-1.5">
                        @if ($item->is_service)
                            <span class="inline-flex h-badge items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.draft') }}">
                                <x-icon name="construction" class="size-3.5" />{{ __('items.list.service') }}
                            </span>
                        @endif
                        @if ($item->track_expiry)
                            <span class="inline-flex h-badge items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.pending') }}">
                                <x-icon name="calendar_today" class="size-3.5" />{{ __('items.list.expiry') }}
                            </span>
                        @endif
                        @if ($item->track_serial)
                            <span class="inline-flex h-badge items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.transit') }}">
                                <x-icon name="qr_code_2" class="size-3.5" />{{ __('items.list.serial') }}
                            </span>
                        @endif
                    </div>
                </x-data-table.cell>
                <x-data-table.cell col="storage" class="whitespace-nowrap text-fg">{{ \App\Support\StorageStatuses::name($item->storage_status) }}</x-data-table.cell>
                <x-data-table.cell col="status"><x-master-data.active-badge :active="$item->active" /></x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
