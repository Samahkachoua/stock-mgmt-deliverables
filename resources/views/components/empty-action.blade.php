{{--
    The next step offered in an empty state: a link (href) or a Livewire action (action).
    <x-empty-action :href="route('items.create')" icon="add" :label="__('items.new')" primary />
    <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
--}}
@props(['label', 'href' => null, 'action' => null, 'icon' => null, 'primary' => false])

@php
    $classes = $primary
        ? 'flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover'
        : 'flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover';
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" />@endif
        {{ $label }}
    </a>
@else
    <button type="button" wire:click="{{ $action }}" {{ $attributes->class($classes) }}>
        <x-busy-icon :name="$icon" :target="$action" />
        {{ $label }}
    </button>
@endif
