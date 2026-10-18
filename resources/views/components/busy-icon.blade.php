{{--
    The icon of a button or select that asks the server for something other than
    a save (Add, Edit, Delete…, switching warehouse): it turns into the spinner
    while that request runs. After ~200 ms, so fast answers don't flicker
    (CLAUDE.md "Loading states"). Saves use x-submit-button instead.
    <x-busy-icon name="add" target="add" />
    <x-busy-icon target="ask" /> no icon: only the spinner, while it runs.
--}}
@props(['target', 'name' => null])

@if ($name)
    <x-icon :name="$name" wire:loading.delay.remove wire:target="{{ $target }}" :class="$attributes->get('class')" />
@endif
<x-spinner wire:loading.delay wire:target="{{ $target }}" :class="$attributes->get('class')" />
