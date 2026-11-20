{{--
    Globe button opening a short menu of the languages (each in its own language, current one ticked).
    Picking one saves it and reloads the page in that language. Grows with config('ui.locales')
    without taking more room in the top bar.
    `labelled`: also shows the current language's name next to the globe (phone user menu).
--}}
@props(['labelled' => false])
@php($current = app()->getLocale())

<div
    class="relative"
    x-data="{ open: false }"
    @keydown.escape.stop="open = false; $refs.trigger.focus()"
    @click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        data-language-toggle
        title="{{ __('app.language') }}"
        aria-label="{{ __('app.language') }}: {{ config('ui.locales')[$current] ?? $current }}"
        aria-haspopup="menu"
        :aria-expanded="open.toString()"
        @class([
            'flex h-control shrink-0 items-center justify-center gap-2 rounded-lg border border-default bg-card text-fg transition-colors hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
            'w-control' => ! $labelled,
            'px-3' => $labelled,
        ])
        @click="open = ! open"
    >
        <x-icon name="language" class="size-5" />
        @if ($labelled)
            <span class="text-small font-semibold" lang="{{ $current }}">{{ config('ui.locales')[$current] ?? $current }}</span>
            <x-icon name="keyboard_arrow_down" class="size-4 text-muted" />
        @endif
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity
        role="menu"
        aria-label="{{ __('app.language') }}"
        class="absolute end-0 top-full z-50 mt-2 w-48 rounded-xl border border-default bg-card p-1.5 shadow-pop"
    >
        @foreach (config('ui.locales') as $code => $label)
            @php($active = $current === $code)
            <form method="POST" action="{{ route('locale.update', $code) }}">
                @csrf
                <button
                    type="submit"
                    role="menuitemradio"
                    lang="{{ $code }}"
                    aria-checked="{{ $active ? 'true' : 'false' }}"
                    @class([
                        'flex h-control w-full items-center justify-between gap-3 rounded-lg px-3 text-body transition-colors hover:bg-card-hover focus:outline-none focus-visible:bg-card-hover',
                        'font-semibold text-brand' => $active,
                        'text-fg' => ! $active,
                    ])
                >
                    <span>{{ $label }}</span>
                    @if ($active)
                        <x-icon name="check" class="size-5" />
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</div>
