{{--
    A cell of x-data-table. `col` is the key of its column: the column says
    whether the cell is hidden on a tablet (priority) and what it is on a
    phone card (card), and gives the card its label.
--}}
@props(['align' => 'start', 'col' => null])
@aware(['columns' => []])

@php
    $column = $col !== null ? ($columns[$col] ?? []) : [];
    $card = $column['card'] ?? null;
@endphp

<td
    @if (filled($column['label'] ?? null) && ! in_array($card, ['title', 'badge', 'hide'], true)) data-label="{{ $column['label'] }}" @endif
    @if ($card) data-card="{{ $card }}" @endif
    @if (($column['priority'] ?? 1) > 1) data-priority="{{ $column['priority'] }}" @endif
    {{ $attributes->class(['px-cell py-cell-y first:ps-edge', 'text-end' => $align === 'end', 'text-start' => $align !== 'end']) }}
>{{ $slot }}</td>
