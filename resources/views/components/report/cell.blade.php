{{--
    One report cell (App\Reports\Report "Cell"): plain text, a status-coloured
    badge (tone), the transaction type badge (type), or a code kept left to right.
    <x-report.cell :cell="$cells['qty']" />
--}}
@props(['cell'])

@php
    $cell = is_string($cell) ? ['text' => $cell] : $cell;
    $text = $cell['text'] ?? '';
@endphp

@if ($text === '')
    <span class="text-muted" aria-hidden="true">&mdash;</span>
@elseif (isset($cell['type']))
    <x-type-badge :code="$cell['type']" />
@elseif (isset($cell['tone']))
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.'.$cell['tone']) }}">
        @if ($cell['ltr'] ?? false)<span dir="ltr">{{ $text }}</span>@else{{ $text }}@endif
    </span>
@elseif ($cell['ltr'] ?? false)
    {{-- Codes and references never break across lines. --}}
    <span dir="ltr" @class(['tabular whitespace-nowrap', 'font-semibold text-fg' => $cell['strong'] ?? false, 'text-muted' => ! ($cell['strong'] ?? false)])>{{ $text }}</span>
@else
    <span @class([($cell['nowrap'] ?? false) ? 'whitespace-nowrap' : 'whitespace-pre-line', 'font-semibold text-fg' => $cell['strong'] ?? false, 'text-fg' => ! ($cell['strong'] ?? false)])>{{ $text }}</span>
@endif
