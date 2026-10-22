{{--
    An item's storage status, e.g. "Chilled (2 to 8 °C)", as a small neutral
    chip; nothing when the item has none.
    <x-storage-status :label="$line['storage']" />
--}}
@props(['label' => null])

@if (filled($label))
    <span {{ $attributes->class('inline-flex items-center gap-1 rounded-md border border-default px-1.5 text-small font-semibold text-muted') }}>
        <x-icon name="thermostat" class="size-3.5" />
        <span>{{ $label }}</span>
    </span>
@endif
