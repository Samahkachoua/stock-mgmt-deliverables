{{--
    The search icon at the start of a debounced search field; it turns into a
    spinner while that search is running (CLAUDE.md "Loading states").
    Goes inside the field's `relative` wrapper; the input keeps `ps-10`.
    <x-input-spinner target="search" />
--}}
@props(['target'])

<span {{ $attributes->class('pointer-events-none absolute start-3 top-1/2 grid size-5 -translate-y-1/2 place-items-center text-muted') }}>
    <x-icon name="search" wire:loading.delay.remove wire:target="{{ $target }}" />
    <x-spinner wire:loading.delay wire:target="{{ $target }}" />
</span>
