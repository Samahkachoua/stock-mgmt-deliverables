{{-- Shared shell for every settings tab: page header + tab bar. Tabs the user may not open are hidden. --}}
<div>
    @php
        // The open tab, by the page's address: a Livewire update (rows loading, a save) runs
        // on its own route, so request()->routeIs() would lose it after the first paint.
        $path = trim(\Livewire\Livewire::originalPath(), '/');
        $isOpen = fn (array $item) => trim(parse_url(route($item['route']), PHP_URL_PATH) ?? '', '/') === $path;
        // Home › Settings › the open tab.
        $currentTab = collect(config('ui.settings_tabs'))->first($isOpen);
    @endphp
    @if ($currentTab !== null)
        <x-breadcrumbs class="mb-2" :items="[[__('settings.title'), route('settings')]]" :current="__($currentTab['label'])" />
    @endif

    <div class="flex items-center gap-3">
        <div>
            <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ __('settings.title') }}</h1>
            <p class="mt-1 text-body text-muted">{{ __('settings.subtitle') }}</p>
        </div>
    </div>

    <nav class="relative mt-section overflow-x-auto border-b border-default" aria-label="{{ __('settings.tabs.label') }}">
        <ul class="flex gap-1">
            @foreach (config('ui.settings_tabs') as $tab)
                @can($tab['permission'])
                    @php($active = $isOpen($tab))
                    <li>
                        {{-- Active tab in the brand blue (text-link stays readable on the dark card). --}}
                        <a
                            href="{{ route($tab['route']) }}"
                            wire:navigate
                            @if ($active) aria-current="page" @endif
                            @class([
                                '-mb-px flex h-control items-center gap-2 whitespace-nowrap rounded-t-lg border-b-2 px-3 text-body font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40',
                                'border-brand bg-brand-soft text-link' => $active,
                                'border-transparent text-muted hover:bg-card-hover hover:text-fg' => ! $active,
                            ])
                        >
                            <x-icon :name="$tab['icon']" />
                            {{ __($tab['label']) }}
                        </a>
                    </li>
                @endcan
            @endforeach
        </ul>
    </nav>

    <div class="mt-section">
        {{ $slot }}
    </div>
</div>
