{{--
    "Rows per page" dropdown at the start of a list's toolbar, before the search
    (x-filters-bar, x-report.filters). Shows itself only for a Livewire component
    using App\Livewire\Concerns\WithPerPage; bound to its `perPage`.
    Narrow, with a little room between the number and the arrow.
--}}
@props(['options' => null])

@php
    $component = $__livewire ?? null;
    $options ??= $component !== null && method_exists($component, 'perPageOptions') ? $component->perPageOptions() : [];
@endphp

@if ($options !== [])
    <div {{ $attributes->class('flex h-control shrink-0 items-center gap-2') }}>
        <label for="per-page" class="whitespace-nowrap text-body text-muted max-md:sr-only"> {{ __('transactions.list.rows_per_page') }}</label>
        <div class="relative">
            <select
                id="per-page"
                wire:model.live="perPage"
                data-per-page
                title="{{ __('transactions.list.rows_per_page') }}"
                class="h-control w-auto min-w-[5rem] appearance-none rounded-lg border border-control bg-card pe-9 ps-3 text-start text-body font-semibold text-fg tabular focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
            >
                @foreach ($options as $size)
                    <option value="{{ $size }}">{{ \App\Support\Format::qty($size) }}</option>
                @endforeach
            </select>
            <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
        </div>
    </div>
@endif
