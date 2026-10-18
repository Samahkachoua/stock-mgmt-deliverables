{{--
    A region that reloads (a list or report table): while its component is
    busy, the current rows stay in place but dimmed, with a thin progress bar on
    top. Shown after ~200 ms, so fast answers don't flicker (CLAUDE.md "Loading states").

    <x-table-loading>table</x-table-loading>
    `target`: the Livewire properties / actions that reload it; left out, every
              request of the component except an export.
    `busy`:   true while the rows are not there yet (skeleton rows).
--}}
@props(['target' => null, 'busy' => false])

@php
    $wireTarget = $target ? 'wire:target="'.e($target).'"' : 'wire:target.except="export"';
@endphp

<div {{ $attributes->class('relative') }} @if ($busy) aria-busy="true" @endif wire:loading.delay.attr="aria-busy" {!! $wireTarget !!}>
    <div
        wire:loading.delay
        {!! $wireTarget !!}
        role="progressbar"
        aria-label="{{ __('app.loading.list') }}"
        class="absolute inset-x-0 top-0 z-10 h-1 overflow-hidden bg-brand/20"
    >
        <div class="absolute inset-y-0 w-1/3 animate-progress rounded-full bg-brand"></div>
    </div>

    <div wire:loading.delay.class="opacity-50 pointer-events-none" {!! $wireTarget !!} class="transition-opacity">
        {{ $slot }}
    </div>
</div>
