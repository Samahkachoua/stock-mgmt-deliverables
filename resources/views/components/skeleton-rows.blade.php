{{--
    Grey bars in place of a table's rows on its first load, one per column
    (CLAUDE.md "Loading states"). Goes inside a <tbody>.
    <x-skeleton-rows :columns="8" />
--}}
@props(['columns', 'rows' => 6])

@php($widths = ['w-24', 'w-32', 'w-20', 'w-28', 'w-16'])

@for ($row = 0; $row < $rows; $row++)
    <tr class="h-row" aria-hidden="true">
        @for ($column = 0; $column < $columns; $column++)
            <td class="px-cell first:ps-edge last:pe-edge">
                <span @class(['block h-3 max-w-full animate-pulse rounded bg-card-hover', $widths[($row + $column) % count($widths)]])></span>
            </td>
        @endfor
    </tr>
@endfor
