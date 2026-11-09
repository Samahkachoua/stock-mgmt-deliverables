{{-- Stock by expiry: the Stock page (livewire/stock/page) with one row per lot (batch + expiry date). --}}
@use('App\Support\Format')
<div class="flex flex-col gap-section">
    <x-page-header icon="calendar_today" :title="__('reports.stock_by_expiry.title')" :subtitle="__('reports.stock_by_expiry.description')">
        <x-slot:actions>
            <x-report.export-buttons :exportable="$exportable" />
        </x-slot:actions>
    </x-page-header>

    <x-report.filters :definitions="$definition->filters()" :choices="$choices" :values="$filters" :defaults="$defaults" />

    <x-data-table
        :columns="[
            'item' => ['label' => __('reports.columns.item'), 'card' => 'title'],
            'code' => ['label' => __('reports.columns.code')],
            'customer' => ['label' => __('reports.columns.customer'), 'priority' => 2],
            'warehouse' => ['label' => __('reports.columns.warehouse'), 'priority' => 2],
            'expiry' => ['label' => __('reports.columns.expiry_date')],
            'storage' => ['label' => __('reports.columns.storage_status'), 'priority' => 2],
            'qty' => ['label' => __('reports.columns.qty_base'), 'align' => 'end'],
            'reserved' => ['label' => __('reports.columns.reserved'), 'align' => 'end'],
            'available' => ['label' => __('reports.columns.available'), 'align' => 'end'],
        ]"
        :paginator="$rows"
        :footer="$totals === null ? null : [
            'label' => trans_choice('reports.stock_on_hand.footer_total', $totals['rows'], ['count' => Format::qty($totals['rows'])]),
            'cells' => [
                'qty' => Format::quantityTotal($totals['qty'], $totals['units']),
                'reserved' => Format::quantityTotal($totals['reserved'], $totals['units']),
                'available' => Format::quantityTotal($totals['available'], $totals['units']),
            ],
        ]"
        :loading="! $rowsLoaded"
        :empty-title="__('reports.stock_on_hand.empty_title')"
        :empty-body="__('reports.stock_on_hand.empty_body')"
    >
        <x-slot:empty-action>
            <div class="flex flex-wrap justify-center gap-3">
                @if ($filters['search'] !== '' || $filters['customer'] !== '' || $filters['storage'] !== '' || $filters['expiring'] !== '')
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
            <x-data-table.row wire:key="stock-by-expiry-{{ $rows->firstItem() + $loop->index }}" :href="$row['link']" :label="__('reports.stock_on_hand.open', ['item' => $cells['item']['text']])">
                <x-data-table.cell col="item" class="font-semibold text-fg xl:min-w-48"><bdi>{{ $cells['item']['text'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="code" class="whitespace-nowrap"><span dir="ltr" class="tabular text-muted">{{ $cells['code']['text'] }}</span></x-data-table.cell>
                <x-data-table.cell col="customer" class="text-fg xl:min-w-32"><bdi>{{ $cells['customer'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="warehouse" class="text-fg"><bdi>{{ $cells['warehouse'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="expiry" class="whitespace-nowrap"><x-report.cell :cell="$cells['expiry']" /></x-data-table.cell>
                <x-data-table.cell col="storage" class="whitespace-nowrap text-fg"><x-report.cell :cell="$cells['storage']" /></x-data-table.cell>
                <x-data-table.cell col="qty" align="end" class="whitespace-nowrap"><x-report.cell :cell="$cells['qty']" /></x-data-table.cell>
                <x-data-table.cell col="reserved" align="end" class="whitespace-nowrap">
                    @if ($cells['reserved']['text'] !== '')
                        <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.pending') }}">
                            <x-icon name="lock" class="size-4" />
                            {{ $cells['reserved']['text'] }}
                        </span>
                    @else
                        <span class="text-muted" aria-hidden="true">&mdash;</span>
                    @endif
                </x-data-table.cell>
                <x-data-table.cell col="available" align="end" class="whitespace-nowrap"><x-report.cell :cell="$cells['available']" /></x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
