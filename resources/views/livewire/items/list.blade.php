@use('App\Support\Format')

@php($filtered = $search !== '' || $customer !== '' || $status !== '')

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
                    class="flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface"
                >
                    <x-icon name="add" />
                    {{ __('items.new') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    <x-filters-bar :customers="$customers" :statuses="$statuses" :search-placeholder="__('items.search')" />

    <x-data-table
        :columns="[
            'code' => ['label' => __('items.list.code')],
            'name' => ['label' => __('items.list.name'), 'card' => 'title'],
            'customer' => ['label' => __('items.list.customer')],
            'base_unit' => ['label' => __('items.list.base_unit'), 'priority' => 2],
            'other_units' => ['label' => __('items.list.other_units'), 'priority' => 2, 'card' => 'hide'],
            'tracking' => ['label' => __('items.list.tracking'), 'priority' => 2],
            'status' => ['label' => __('settings.status'), 'card' => 'badge'],
        ]"
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
            @php($converter = $item->converter())
            <x-data-table.row wire:key="item-{{ $item->id }}" :href="route('items.edit', $item)" :label="__('items.open', ['name' => $item->name()])">
                <x-data-table.cell col="code"><span dir="ltr" class="tabular font-semibold text-fg">{{ $item->sku }}</span></x-data-table.cell>
                <x-data-table.cell col="name" class="font-semibold text-fg"><bdi>{{ $item->name() }}</bdi></x-data-table.cell>
                <x-data-table.cell col="customer" class="text-fg"><bdi>{{ $item->customer->name }}</bdi></x-data-table.cell>
                <x-data-table.cell col="base_unit" class="text-fg">{{ Format::unit($converter->base()) }}</x-data-table.cell>
                <x-data-table.cell col="other_units" class="text-muted">
                    @forelse (array_reverse(array_slice($converter->factors(), 0, -1, true), true) as $unit => $factor)
                        <span class="whitespace-nowrap">{{ __('items.units.equation', ['unit' => Format::unit($unit), 'qty' => Format::qtyUnit($factor, $converter->base())]) }}</span>@unless ($loop->last)<span aria-hidden="true"> · </span>@endunless
                    @empty
                        <span aria-hidden="true">&mdash;</span>
                    @endforelse
                </x-data-table.cell>
                <x-data-table.cell col="tracking">
                    <div class="flex flex-wrap gap-1.5">
                        @if ($item->is_service)
                            <span class="inline-flex h-6 items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.draft') }}">
                                <x-icon name="construction" class="size-3.5" />{{ __('items.list.service') }}
                            </span>
                        @endif
                        @if ($item->track_expiry)
                            <span class="inline-flex h-6 items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.pending') }}">
                                <x-icon name="calendar_today" class="size-3.5" />{{ __('items.list.expiry') }}
                            </span>
                        @endif
                        @if ($item->track_serial)
                            <span class="inline-flex h-6 items-center gap-1 rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.transit') }}">
                                <x-icon name="qr_code_2" class="size-3.5" />{{ __('items.list.serial') }}
                            </span>
                        @endif
                    </div>
                </x-data-table.cell>
                <x-data-table.cell col="status"><x-master-data.active-badge :active="$item->active" /></x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
