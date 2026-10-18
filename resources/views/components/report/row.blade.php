{{--
    A report row: its cells in column order; the whole row opens its link (the
    document, the item...) when it has one.
    <x-report.row :row="['cells' => [...], 'link' => $url]" :columns="$definition->columns()" />
--}}
@props(['row', 'columns'])

@if ($row['link'])
    <x-data-table.row {{ $attributes }} :href="$row['link']" :label="__('reports.page.open_row')">
        @foreach ($columns as $key => $column)
            <x-data-table.cell :col="$key" :align="$column['align'] ?? 'start'"><x-report.cell :cell="$row['cells'][$key] ?? ''" /></x-data-table.cell>
        @endforeach
    </x-data-table.row>
@else
    <tr {{ $attributes->class('h-row') }}>
        @foreach ($columns as $key => $column)
            <x-data-table.cell :col="$key" :align="$column['align'] ?? 'start'"><x-report.cell :cell="$row['cells'][$key] ?? ''" /></x-data-table.cell>
        @endforeach
        <td data-card="hide" class="pe-edge"></td>
    </tr>
@endif
