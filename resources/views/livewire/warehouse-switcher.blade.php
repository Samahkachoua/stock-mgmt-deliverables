<div class="flex min-w-0 items-center gap-2">
    <x-icon name="apartment" class="hidden size-5 text-muted md:block" />
    <label for="warehouse-selector" class="sr-only">{{ __('app.select_warehouse') }}</label>
    <div class="relative">
        <select
            id="warehouse-selector"
            wire:model.live="selected"
            wire:loading.attr="disabled"
            wire:target="selected"
            class="h-control w-36 min-w-0 cursor-pointer truncate md:w-auto md:min-w-40 appearance-none rounded-lg border border-control bg-card pe-9 ps-3 text-body font-semibold text-fg focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20 disabled:cursor-wait disabled:opacity-60"
        >
            @foreach ($this->warehouses as $warehouse)
                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
            @endforeach
        </select>
        <x-busy-icon name="keyboard_arrow_down" target="selected" class="pointer-events-none absolute end-2.5 top-1/2 size-5 -translate-y-1/2 text-muted" />
    </div>
</div>
