{{-- Shared shell for every settings tab: page header + tab bar. Tabs the user may not open are hidden. --}}
<div>
    <div class="flex items-center gap-3">
        <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-card-hover">
            <x-icon name="settings" class="size-6 text-muted" />
        </div>
        <div>
            <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ __('settings.title') }}</h1>
            <p class="text-body text-muted">{{ __('settings.subtitle') }}</p>
        </div>
    </div>

    <nav class="relative mt-4 overflow-x-auto border-b border-default" aria-label="{{ __('settings.tabs.label') }}">
        <ul class="flex gap-4">
            @foreach (config('ui.settings_tabs') as $tab)
                @can($tab['permission'])
                    @php($active = request()->routeIs($tab['route']))
                    <li>
                        <a
                            href="{{ route($tab['route']) }}"
                            wire:navigate
                            @if ($active) aria-current="page" @endif
                            @class([
                                '-mb-px flex h-control items-center gap-2 whitespace-nowrap border-b-2 text-body font-semibold transition-colors',
                                'border-brand text-fg' => $active,
                                'border-transparent text-muted hover:text-fg' => ! $active,
                            ])
                        >
                            <x-icon :name="$tab['icon']" @class(['text-brand' => $active]) />
                            {{ __($tab['label']) }}
                        </a>
                    </li>
                @endcan
            @endforeach
        </ul>
    </nav>

    <div class="mt-4">
        {{ $slot }}
    </div>
</div>
