{{--
    One button: shows the moon in light mode (click = dark), the sun in dark mode (click = light).
    Instant: Alpine flips the <html> class first, then Livewire saves the choice.
    Until the user picks one (saved "system", the default), the theme their device gives is shown as current.
--}}
<div
    x-data="{
        theme: @js($theme) === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : @js($theme),
        toggle() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            window.applyTheme(this.theme);
            $wire.setTheme(this.theme);
        },
    }"
    class="contents"
>
    <button
        type="button"
        data-theme-toggle
        :data-current="theme"
        :title="theme === 'dark' ? @js(__('app.theme_to_light')) : @js(__('app.theme_to_dark'))"
        :aria-label="theme === 'dark' ? @js(__('app.theme_to_light')) : @js(__('app.theme_to_dark'))"
        class="grid size-control shrink-0 place-items-center rounded-lg border border-default bg-card text-fg transition-colors hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
        @click="toggle()"
    >
        <span x-show="theme !== 'dark'"><x-icon name="dark_mode" class="size-5" /></span>
        <span x-show="theme === 'dark'" x-cloak><x-icon name="light_mode" class="size-5" /></span>
    </button>
</div>
