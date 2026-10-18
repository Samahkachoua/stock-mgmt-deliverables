{{--
    Search plus optional Customer / Status / Date from + Date to / Direction filters
    for a Livewire list. Binds to the component's `search`, `customer`, `status`,
    `dateFrom`, `dateTo` and `direction` properties and calls `resetFilters()`.
    Leave a filter's prop out to hide it.
    <x-filters-bar :customers="[id => name]" :statuses="[code => label]" dates :date-error="$dateError" :search-placeholder="..." />
    `dates` shows the two date pickers (x-date-range); `date-error` outlines them in red.
    `all-statuses` relabels the status filter's default option (e.g. "All statuses, pending first").

    Layout (CLAUDE.md "Responsive layout"): the search on the first line; the
    other filters below it with equal widths, 4 per row on desktop and 2 on
    tablet. On a phone they sit behind a "Filters · 2" button, in a panel with
    "Apply" and "Clear filters".
--}}
@props([
    'customers' => null,
    'statuses' => null,
    'dates' => false,
    'dateError' => false,
    'directions' => null,
    'searchPlaceholder' => '',
    'allStatuses' => null,
])

@php
    $control = 'h-control w-full appearance-none rounded-lg border border-control bg-card text-body text-fg focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $select = fn (string $id, string $icon, string $label, array $options) => compact('id', 'icon', 'label', 'options');
    $before = array_filter([
        $customers === null ? null : $select('customer', 'keyboard_arrow_down', __('transactions.filters.customer'), ['' => __('transactions.filters.all_customers')] + $customers),
        $statuses === null ? null : $select('status', 'keyboard_arrow_down', __('transactions.filters.status'), ['' => $allStatuses ?? __('transactions.filters.all_statuses')] + $statuses),
    ]);
    $after = array_filter([
        $directions === null ? null : $select('direction', 'swap_horiz', __('transfers.direction.label'), $directions),
    ]);
    $models = [...array_column($before, 'id'), ...($dates ? ['dateFrom', 'dateTo'] : []), ...array_column($after, 'id')];
    $button = 'flex h-control shrink-0 items-center justify-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover';
@endphp

<div
    {{ $attributes->class('flex flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card print:hidden') }}
    role="search"
    data-filters-bar
    x-data="{
        panel: false,
        models: @js($models),
        get active() {
            return this.models.filter((model) => (this.$wire[model] ?? '') !== '').length;
        },
    }"
    x-on:keydown.escape.window="panel = false"
>
    <div class="flex items-center gap-3">
        <div class="relative min-w-0 flex-1 md:max-w-sm">
            <x-input-spinner target="search" />
            <label for="filter-search" class="sr-only">{{ __('transactions.filters.search') }}</label>
            <input id="filter-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $searchPlaceholder }}" class="{{ $control }} pe-3 ps-10 placeholder:text-subtle">
        </div>

        @if ($models !== [])
            {{-- Phone: the other filters are in a panel. --}}
            <button type="button" data-filters-button x-on:click="panel = true" x-bind:aria-expanded="panel.toString()" class="{{ $button }} md:hidden">
                <x-icon name="tune" class="text-muted" />
                <span x-text="active > 0 ? @js(__('transactions.filters.filters_count')).replace(':count', active) : @js(__('transactions.filters.filters'))">{{ __('transactions.filters.filters') }}</span>
            </button>
        @endif

        {{-- Clears the filters this list remembers for the user (session), back to the first page. --}}
        <button type="button" wire:click="resetFilters" @class([$button, 'ms-auto', 'max-md:hidden' => $models !== []])>
            <x-icon name="filter_alt_off" class="text-muted" />
            <span @class(['max-md:sr-only' => $models === []])>{{ __('transactions.filters.reset') }}</span>
        </button>
    </div>

    @if ($models !== [])
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
            <div data-filters-grid class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($before as $filter)
                    <div class="relative min-w-0" data-filter>
                        <label for="filter-{{ $filter['id'] }}" class="sr-only">{{ $filter['label'] }}</label>
                        <select id="filter-{{ $filter['id'] }}" wire:model.live="{{ $filter['id'] }}" class="{{ $control }} pe-10 ps-3">
                            @foreach ($filter['options'] as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                        <x-icon :name="$filter['icon']" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                    </div>
                @endforeach

                @if ($dates)
                    <x-date-range :error="$dateError" data-filter />
                @endif

                @foreach ($after as $filter)
                    <div class="relative min-w-0" data-filter>
                        <label for="filter-{{ $filter['id'] }}" class="sr-only">{{ $filter['label'] }}</label>
                        <select id="filter-{{ $filter['id'] }}" wire:model.live="{{ $filter['id'] }}" class="{{ $control }} pe-10 ps-3">
                            @foreach ($filter['options'] as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                        <x-icon :name="$filter['icon']" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
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
