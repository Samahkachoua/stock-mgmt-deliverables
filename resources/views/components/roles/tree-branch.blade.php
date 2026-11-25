{{--
    One branch of the Settings › Roles tree (a section or a module): chevron to
    open/close, a box that is ticked when every permission below is, half-ticked
    when some are, and "3 of 4". Inside the tree's x-data (roles.blade.php),
    which holds has / set / state / isOpen. The slot is the list of children.
    <x-roles.tree-branch key="IN" :label="..." :ids="[...]" :open="false">…</x-roles.tree-branch>
--}}
@props(['key', 'label', 'ids', 'open' => false, 'section' => false])

@php
    $node = 'roles-node-'.$key;
    $default = $open ? 'true' : 'false';
@endphp

<li x-data="{ ids: @js($ids) }" x-show="any(ids)" wire:key="{{ $node }}">
    <div class="flex items-center gap-1 rounded-lg hover:bg-surface">
        <button
            type="button"
            x-on:click="toggleOpen(@js($key), {{ $default }})"
            x-bind:aria-expanded="isOpen(@js($key), {{ $default }}).toString()"
            aria-controls="{{ $node }}"
            class="grid size-control shrink-0 place-items-center rounded-lg text-muted hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
        >
            {{-- Turned on a plain span: Blade directives aren't compiled inside a component tag's attributes. --}}
            <span class="grid place-items-center transition-[rotate]" x-bind:class="isOpen(@js($key), {{ $default }}) && 'rotate-90 rtl:-rotate-90'">
                <x-icon name="chevron_right" class="size-5 rtl:-scale-x-100" />
            </span>
            <span class="sr-only">{{ $label }}</span>
        </button>
        <label class="flex min-h-control min-w-0 flex-1 cursor-pointer items-center gap-3 pe-3">
            <input
                type="checkbox"
                x-bind:checked="state(ids) === 'all'"
                x-effect="$el.indeterminate = state(ids) === 'some'"
                x-on:change="set(ids, $event.target.checked)"
                class="size-5 shrink-0 rounded border-control accent-brand"
            >
            <span @class(['min-w-0 flex-1 break-words text-fg', 'font-semibold' => $section, 'font-medium' => ! $section])>{{ $label }}</span>
            <span class="shrink-0 text-small text-muted tabular" x-text="countText(ids)"></span>
        </label>
    </div>
    <ul id="{{ $node }}" x-show="isOpen(@js($key), {{ $default }})" class="ms-[calc(var(--control)/2)] border-s border-default ps-3">
        {{ $slot }}
    </ul>
</li>
