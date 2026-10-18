{{-- Stock (docs/design/stitch-exports/stock-inventory-balance-list). --}}
<div class="flex flex-col gap-section">
    <x-page-header icon="inventory_2" :title="__('reports.stock_on_hand.title')" :subtitle="__('reports.stock_on_hand.description')">
        <x-slot:actions>
            <x-report.export-buttons :exportable="$exportable" />
        </x-slot:actions>
    </x-page-header>

    <x-report.filters :definitions="$definition->filters()" :choices="$choices" :values="$filters" :defaults="$defaults" />

    <x-data-table
        :columns="[
            'item' => ['label' => __('reports.stock_on_hand.item_and_code'), 'card' => 'title'],
            'customer' => ['label' => __('reports.columns.customer'), 'priority' => 2],
            'warehouse' => ['label' => __('reports.columns.warehouse'), 'priority' => 2],
            'qty' => ['label' => __('reports.columns.qty_base'), 'align' => 'end'],
            'breakdown' => ['label' => __('reports.columns.qty_larger'), 'priority' => 2, 'card' => 'hide'],
            'in_transit' => ['label' => __('reports.columns.in_transit'), 'align' => 'end'],
            'expiry' => ['label' => __('reports.columns.nearest_expiry')],
        ]"
        :paginator="$rows"
        :loading="! $rowsLoaded"
        :empty-title="__('reports.stock_on_hand.empty_title')"
        :empty-body="__('reports.stock_on_hand.empty_body')"
    >
        <x-slot:empty-action>
            <div class="flex flex-wrap justify-center gap-3">
                @if ($filters['search'] !== '' || $filters['customer'] !== '' || $filters['expiring'] !== '')
                    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
                @endif
                @can('stock.in.create')
                    <x-empty-action :href="route('receive.create')" icon="add" :label="__('transactions.types.receive.new')" primary />
                @endcan
            </div>
        </x-slot:empty-action>
        @foreach ($rows as $row)
            @php
                $cells = $row['cells'];
            @endphp
            <x-data-table.row wire:key="stock-{{ $rows->firstItem() + $loop->index }}" :href="$row['link']" :label="__('reports.stock_on_hand.open', ['item' => $cells['item']['text']])">
                <x-data-table.cell col="item" class="xl:min-w-56">
                    <div class="flex flex-wrap items-center gap-2">
                        <bdi class="font-semibold text-fg">{{ $cells['item']['text'] }}</bdi>
                        <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $cells['code']['text'] }}</span>
                    </div>
                </x-data-table.cell>
                <x-data-table.cell col="customer" class="text-fg xl:min-w-32"><bdi>{{ $cells['customer'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="warehouse" class="text-fg"><bdi>{{ $cells['warehouse'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="qty" align="end" class="whitespace-nowrap"><x-report.cell :cell="$cells['qty']" /></x-data-table.cell>
                <x-data-table.cell col="breakdown" class="whitespace-nowrap text-fg"><x-report.cell :cell="$cells['breakdown']" /></x-data-table.cell>
                <x-data-table.cell col="in_transit" align="end" class="whitespace-nowrap">
                    @if ($cells['in_transit']['text'] !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.transit') }}">
                            <x-icon name="local_shipping" class="size-4" />
                            {{ $cells['in_transit']['text'] }}
                        </span>
                    @else
                        <span class="text-muted" aria-hidden="true">&mdash;</span>
                    @endif
                </x-data-table.cell>
                <x-data-table.cell col="expiry"><x-report.cell :cell="$cells['expiry']" /></x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
