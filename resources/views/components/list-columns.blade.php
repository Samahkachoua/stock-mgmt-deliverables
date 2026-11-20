{{--
    "Columns" menu of a list: a checkbox per column to show or hide it.
    <x-list-columns :columns="$columns" list="delivery-note" />
    `columns` is the x-data-table columns array (key => ['label' => ...]); `list` names the list.

    Hiding is one CSS rule on the cells' data-col (x-data-table), so it is instant, survives
    Livewire re-renders (wire:ignore) and covers the phone cards too. The choice is remembered in
    this browser (localStorage `list-columns.<list>`) and sent to the component (hiddenColumns,
    without a request of its own), so Excel and PDF leave the hidden columns out.
    The first column names the row (reference, item...) and can't be hidden.
--}}
@props(['columns', 'list'])

@php
    // Columns without a heading (row actions) are not offered.
    $columns = array_filter($columns, fn (array $column) => filled($column['label'] ?? null));
    $keys = array_keys($columns);
    $first = $keys[0] ?? null;
@endphp

<div
    class="relative"
    wire:ignore
    x-data="{
        open: false,
        hidden: [],
        store: @js('list-columns.'.$list),
        keys: @js($keys),
        first: @js($first),
        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.store) || '[]');
                this.hidden = Array.isArray(saved) ? saved.filter((key) => this.keys.includes(key) && key !== this.first) : [];
            } catch (e) {}
            this.send();
        },
        toggle(key) {
            this.hidden = this.hidden.includes(key) ? this.hidden.filter((k) => k !== key) : [...this.hidden, key];
            try { localStorage.setItem(this.store, JSON.stringify(this.hidden)); } catch (e) {}
            this.send();
        },
        send() {
            $wire.$set('hiddenColumns', this.hidden, false);
        },
        get css() {
            return this.hidden.length === 0 ? '' : this.hidden.map((key) => `table[data-list] [data-col='${key}']`).join(',') + '{display:none !important}';
        },
    }"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
>
    <style x-text="css"></style>

    <button
        type="button"
        data-columns-button
        x-on:click="open = ! open"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="true"
        aria-controls="list-columns-menu"
        class="flex h-control w-full shrink-0 items-center whitespace-nowrap justify-center gap-2 rounded-lg border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover"
    >
        <x-icon name="view_column" class="text-muted" />
        <span>{{ __('app.columns.button') }}</span>
        <span x-cloak x-show="hidden.length > 0" class="whitespace-nowrap tabular text-muted" x-text="'· ' + (keys.length - hidden.length) + '/' + keys.length"></span>
    </button>

    <div
        id="list-columns-menu"
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        class="absolute end-0 top-full z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-default bg-card shadow-pop"
    >
        <p class="border-b border-default px-3 py-2 text-body font-semibold text-fg">{{ __('app.columns.title') }}</p>
        <ul class="max-h-80 overflow-y-auto py-1">
            @foreach ($columns as $key => $column)
                <li>
                    <label @class([
                        'flex min-h-control items-center gap-3 px-3 text-body text-fg',
                        'cursor-pointer hover:bg-card-hover' => $key !== $first,
                        'cursor-not-allowed text-muted' => $key === $first,
                    ])>
                        <input
                            type="checkbox"
                            class="size-4 shrink-0 accent-brand"
                            x-bind:checked="! hidden.includes(@js($key))"
                            @if ($key === $first) checked disabled @else x-on:change="toggle(@js($key))" @endif
                        >
                        <span class="min-w-0 flex-1 truncate">{{ $column['label'] }}</span>
                        @if ($key === $first)
                            <span class="text-small text-muted">{{ __('app.columns.always') }}</span>
                        @endif
                    </label>
                </li>
            @endforeach
        </ul>
    </div>
</div>
