{{--
    Light or Dark. Instant: Alpine flips the <html> class first, then Livewire saves the choice.
    Until the user picks one (saved "system", the default), the theme their device gives is shown as chosen.
--}}
<div
    x-data="{ theme: @js($theme) === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : @js($theme) }"
    class="inline-flex h-control items-center gap-1 rounded-lg bg-card-hover p-1"
    role="radiogroup"
    aria-label="{{ __('app.theme') }}"
>
    @foreach (['light' => 'light_mode', 'dark' => 'dark_mode'] as $value => $icon)
        <button
            type="button"
            role="radio"
            title="{{ __('app.theme_'.$value) }}"
            aria-label="{{ __('app.theme_'.$value) }}"
            :aria-checked="(theme === '{{ $value }}').toString()"
            :class="theme === '{{ $value }}' ? 'bg-card text-fg shadow-card' : 'text-muted hover:text-fg'"
            class="grid h-full w-10 place-items-center rounded-md transition-colors"
            @click="theme = '{{ $value }}'; window.applyTheme('{{ $value }}'); $wire.setTheme('{{ $value }}')"
        >
            <x-icon :name="$icon" class="size-5" />
        </button>
    @endforeach
</div>
