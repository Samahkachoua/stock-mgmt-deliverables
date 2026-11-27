{{--
    Home "Start something new": the first action the user may start is the main
    (brand) button, the next two secondary buttons, all the same width; the rest
    are smaller secondary buttons under "More". Order: config('ui.home_primary'),
    then sidebar order (App\Livewire\Home::creatable()).
    <x-home.quick-start :actions="$create" />
--}}
@props(['actions'])

@php
    $main = $actions->take(3)->values();
    $more = $actions->slice(3)->values();
    // The type colour of each icon (Receive green, Transfer blue…); neutral for "Add new item".
    $tint = fn (array $action) => $action['kind'] !== null ? $action['kind']->colours()['icon'] : 'text-muted';
    $columns = ['', 'sm:grid-cols-1', 'sm:grid-cols-2', 'sm:grid-cols-3'][$main->count()] ?? 'sm:grid-cols-3';
@endphp

<section aria-labelledby="home-new" class="flex min-w-0 flex-col gap-4 rounded-2xl border border-default bg-card p-card shadow-card sm:p-section-lg">
    <h2 id="home-new" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.start') }}</h2>
    @if ($actions->isEmpty())
        <p class="text-body text-muted">{{ __('home.nothing_to_create') }}</p>
    @else
        <ul @class(['grid gap-3', $columns])>
            @foreach ($main as $action)
                <li class="min-w-0">
                    <a
                        href="{{ $action['url'] }}"
                        wire:navigate
                        data-quick-start="{{ $loop->first ? 'primary' : 'secondary' }}"
                        title="{{ $action['title'] }}"
                        @class([
                            'flex h-full min-h-tile items-center justify-center gap-2 rounded-lg px-4 text-body font-semibold shadow-card transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
                            'bg-brand text-on-brand hover:bg-brand-add-hover' => $loop->first,
                            'border border-control bg-card text-fg hover:bg-card-hover' => ! $loop->first,
                        ])
                    >
                        <x-icon :name="$loop->first ? 'add' : $action['icon']" @class(['size-5 shrink-0', $tint($action) => ! $loop->first]) />
                        <span class="min-w-0 break-words">{{ $loop->first ? $action['title'] : $action['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($more->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-small text-muted">{{ __('home.more') }}</span>
                @foreach ($more as $action)
                    <a
                        href="{{ $action['url'] }}"
                        wire:navigate
                        data-quick-start="more"
                        title="{{ $action['title'] }}"
                        class="inline-flex h-control items-center gap-2 rounded-full border border-control bg-card px-3 text-body font-semibold text-fg transition-colors duration-150 hover:bg-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                    >
                        <x-icon :name="$action['icon']" @class(['size-4 shrink-0', $tint($action)]) />
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    @endif
</section>
