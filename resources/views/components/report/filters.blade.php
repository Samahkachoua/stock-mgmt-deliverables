{{--
    Filters of a report (App\Reports\Filter), bound to the Livewire `filters` array.
    <x-report.filters :definitions="$definition->filters()" :choices="$choices" :values="$filters" :defaults="$defaults" />
    `date-error`: "Date from" is after "Date to": both date fields are outlined in red.
    `defaults`: the values of a cleared report, to count the filters in use.

    Layout (CLAUDE.md "Responsive layout"): the search on the first line; the
    other filters below it with equal widths, 4 per row on desktop and 2 on
    tablet. On a phone they sit behind a "Filters · 2" button, in a panel with
    "Apply" and "Clear filters".
--}}
@props(['definitions', 'choices', 'values', 'dateError' => false, 'defaults' => []])

@php
    $control = 'h-control w-full rounded-lg border border-control bg-card text-body text-fg focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $invalid = 'h-control w-full rounded-lg border border-danger bg-card text-body text-fg focus:outline-none focus:ring-2 focus:ring-danger/20';
    $button = 'flex h-control shrink-0 items-center justify-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover';
    $searches = array_filter($definitions, fn ($filter) => $filter->type === 'search');
    $others = array_filter($definitions, fn ($filter) => $filter->type !== 'search');
    $active = count(array_filter($others, fn ($filter) => ($values[$filter->name] ?? '') !== ($defaults[$filter->name] ?? '')));
@endphp

<div
    {{ $attributes->class('flex flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card print:hidden') }}
    role="search"
    data-filters-bar
    x-data="{ panel: false }"
    x-on:keydown.escape.window="panel = false"
>
    <div class="flex items-end gap-3">
        @foreach ($searches as $filter)
            @php($id = 'report-filter-'.$filter->name)
            <div class="min-w-0 flex-1 md:max-w-sm">
                <label for="{{ $id }}" class="mb-0.5 block text-small font-semibold text-muted">{{ __($filter->label) }}</label>
                <div class="relative">
                    <x-input-spinner target="filters.{{ $filter->name }}" />
                    <input id="{{ $id }}" type="search" wire:model.live.debounce.300ms="filters.{{ $filter->name }}" placeholder="{{ __('reports.filters.search_placeholder') }}" class="{{ $control }} pe-3 ps-10 placeholder:text-subtle">
                </div>
            </div>
        @endforeach

        @if ($others !== [])
            {{-- Phone: the other filters are in a panel. --}}
            <button type="button" data-filters-button x-on:click="panel = true" x-bind:aria-expanded="panel.toString()" @class([$button, 'md:hidden', 'flex-1' => $searches === []])>
                <x-icon name="tune" class="text-muted" />
                {{ $active > 0 ? __('transactions.filters.filters_count', ['count' => \App\Support\Format::qty($active)]) : __('transactions.filters.filters') }}
            </button>
        @endif

        {{-- Clears the filters this list remembers for the user (session), back to the first page. --}}
        <button type="button" wire:click="resetFilters" @class([$button, 'ms-auto', 'max-md:hidden' => $others !== []])>
            <x-icon name="filter_alt_off" class="text-muted" />
            {{ __('transactions.filters.reset') }}
        </button>
    </div>

    @if ($others !== [])
        <div x-cloak x-show="panel" x-transition.opacity x-on:click="panel = false" class="fixed inset-0 z-40 bg-scrim md:hidden" aria-hidden="true"></div>

        <div
            data-filters-panel
            x-bind:class="{ 'max-md:hidden': ! panel, 'max-md:flex': panel }"
            class="max-md:hidden max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-50 max-md:max-h-[85vh] max-md:flex-col max-md:gap-4 max-md:overflow-y-auto max-md:rounded-t-xl max-md:border-t max-md:border-default max-md:bg-card max-md:p-card max-md:shadow-pop"
        >
            <div class="flex items-center justify-between md:hidden">
                <h2 class="text-h3 font-semibold text-fg">{{ __('transactions.filters.filters') }}</h2>
                <button type="button" x-on:click="panel = false" class="grid size-control place-items-center rounded-lg text-muted hover:bg-card-hover hover:text-fg">
                    <x-icon name="close" />
                    <span class="sr-only">{{ __('feedback.close') }}</span>
                </button>
            </div>

            {{-- Equal widths: 4 per row on desktop, 2 on tablet, 1 on phone. --}}
            <div data-filters-grid class="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($others as $filter)
                    @php($id = 'report-filter-'.$filter->name)
                    <div class="relative min-w-0" data-filter>
                        @if ($filter->type === 'toggle')
                            {{-- A pressed / not pressed button, like "Expiring < 30 days". --}}
                            <button
                                type="button"
                                wire:click="$set('filters.{{ $filter->name }}', '{{ ($values[$filter->name] ?? '') === '1' ? '' : '1' }}')"
                                aria-pressed="{{ ($values[$filter->name] ?? '') === '1' ? 'true' : 'false' }}"
                                @class([
                                    'flex h-control w-full items-center gap-2 rounded-lg border px-3 text-body font-semibold',
                                    config('ui.statuses.pending') => ($values[$filter->name] ?? '') === '1',
                                    'border-control bg-card text-fg hover:bg-card-hover' => ($values[$filter->name] ?? '') !== '1',
                                ])
                            >
                                <x-icon name="lock_clock" />
                                <span class="truncate">{{ __($filter->label) }}</span>
                            </button>
                        @else
                            <label for="{{ $id }}" class="mb-0.5 block truncate text-small font-semibold text-muted">{{ __($filter->label) }}</label>
                            @switch($filter->type)
                                @case('date')
                                    <input id="{{ $id }}" type="date" wire:model.live="filters.{{ $filter->name }}" @if ($dateError) aria-invalid="true" @endif class="{{ $dateError ? $invalid : $control }} px-3">
                                    @break
                                @case('number')
                                    <input id="{{ $id }}" type="number" inputmode="numeric" min="{{ $filter->min }}" max="{{ $filter->max }}" wire:model.live.debounce.400ms="filters.{{ $filter->name }}" class="{{ $control }} px-3 tabular">
                                    @break
                                @default
                                    <div class="relative">
                                        <select id="{{ $id }}" wire:model.live="filters.{{ $filter->name }}" class="{{ $control }} appearance-none pe-10 ps-3">
                                            @foreach ($choices[$filter->name] ?? [] as $value => $text)
                                                <option value="{{ $value }}">{{ $text }}</option>
                                            @endforeach
                                        </select>
                                        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                                    </div>
                            @endswitch
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Said next to the dates the moment they are in the wrong order, and for as long as they are. --}}
            @if ($dateError)
                <p data-date-error class="flex items-center gap-1.5 text-body font-semibold text-danger md:mt-3" role="alert">
                    <x-icon name="report" class="size-4" />
                    {{ __('feedback.date_order') }}
                </p>
            @endif

            <div class="flex gap-3 md:hidden">
                <button type="button" wire:click="resetFilters" x-on:click="panel = false" class="{{ $button }} flex-1">
                    {{ __('transactions.filters.reset') }}
                </button>
                <button type="button" data-filters-apply x-on:click="panel = false" class="flex h-control flex-1 items-center justify-center rounded-lg bg-brand px-3 text-body font-semibold text-on-brand hover:bg-brand-hover">
                    {{ __('transactions.filters.apply') }}
                </button>
            </div>
        </div>
    @endif
</div>
