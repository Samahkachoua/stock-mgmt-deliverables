@props(['item'])

{{--
    One sidebar entry with its icon (App\Support\Navigation\Sidebar item). In the
    icon rail the label is a tooltip (showTip, in the app layout) and the pending
    badge sits on the icon's corner.
--}}
<a
    href="{{ $item['url'] }}"
    data-label="{{ $item['label'] }}"
    data-pending="{{ $item['pendingText'] }}"
    wire:navigate
    x-on:click="nav = false; tip = null"
    x-on:mouseenter="showTip($el)"
    x-on:mouseleave="tip = null"
    x-on:focus="showTip($el)"
    x-on:blur="tip = null"
    @if ($item['active']) aria-current="page" @endif
    {{ $attributes->class([
        'relative flex h-control items-center justify-start gap-3 rounded-lg px-3 text-body font-medium transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 md:justify-center xl:justify-start xl:collapsed:justify-center md:max-xl:expanded:justify-start',
        'bg-nav-active text-link' => $item['active'],
        'text-nav hover:bg-card-hover hover:text-fg' => ! $item['active'],
    ]) }}
>
    <x-icon :name="$item['icon']" :class="$item['active'] ? 'text-link' : 'text-nav-icon'" />
    <span class="md:sr-only xl:not-sr-only xl:collapsed:sr-only md:max-xl:expanded:not-sr-only">{{ $item['label'] }}</span>
    @if ($item['pending'] > 0)
        {{-- Waiting for this user's approval (not their own documents): a round count at the row's end. --}}
        <span class="ms-auto inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-small font-bold shadow-badge tabular md:absolute md:-top-1 md:end-0 md:ms-0 xl:static xl:ms-auto xl:collapsed:absolute xl:collapsed:ms-0 md:max-xl:expanded:static md:max-xl:expanded:ms-auto {{ config('ui.badges.pending') }}">
            <span aria-hidden="true">{{ \App\Support\Format::qty($item['pending']) }}</span>
            <span class="sr-only">{{ $item['pendingText'] }}</span>
        </span>
    @endif
</a>
