{{--
    Home's own warehouse filter (CLAUDE.md rule 7: a page filter, never in the top
    bar): only for users with several warehouses; starts on their default one,
    kept in the session (Home::$warehouse). Bound with wire:model.live.
    <x-home.warehouse-filter :options="$warehouses" />
--}}
@props(['options'])

@if ($options !== null)
    <label class="relative flex h-control min-w-0 items-center gap-2 rounded-lg border border-control bg-card ps-3 text-body shadow-card focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/20">
        <span class="shrink-0 text-muted">{{ __('home.warehouse') }}</span>
        <select wire:model.live="warehouse" data-home-warehouse class="h-full min-w-0 flex-1 appearance-none truncate bg-transparent pe-10 font-semibold text-fg focus:outline-none">
            @foreach ($options as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
    </label>
@endif
