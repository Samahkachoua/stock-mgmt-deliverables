{{-- Home: large links only (no charts, no widgets). --}}
@use('App\Support\Format')

<x-layouts.app :title="__('home.page_title')">
    <div class="flex flex-col gap-section">
        <x-page-header icon="home" :title="__('home.title', ['name' => auth()->user()->full_name])" :subtitle="__('home.subtitle')" />

        @if ($approvals->isNotEmpty() || $arriving !== null)
            <section aria-labelledby="home-waiting" class="flex flex-col gap-3">
                <h2 id="home-waiting" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.waiting') }}</h2>
                <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($approvals as $approval)
                        <li>
                            <a
                                href="{{ $approval['type']->route() }}"
                                wire:navigate
                                class="flex min-h-20 items-center gap-4 rounded-xl border bg-card p-card shadow-card hover:bg-card-hover {{ $approval['count'] > 0 ? config('ui.statuses.pending') : 'border-default' }}"
                            >
                                <x-icon name="verified_user" class="size-8 shrink-0" />
                                <span class="flex flex-col">
                                    <span class="text-h1 font-semibold tabular">{{ Format::qty($approval['count']) }}</span>
                                    <span class="text-body font-semibold">{{ trans_choice('home.approvals', $approval['count'], ['type' => $approval['type']->label('title')]) }}</span>
                                </span>
                                <x-icon name="chevron_right" class="ms-auto shrink-0 rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                    @if ($arriving !== null)
                        <li>
                            <a
                                href="{{ $transfers->route('incoming') }}"
                                class="flex min-h-20 items-center gap-4 rounded-xl border bg-card p-card shadow-card hover:bg-card-hover {{ $arriving > 0 ? config('ui.statuses.transit') : 'border-default' }}"
                            >
                                <x-icon name="local_shipping" class="size-8 shrink-0" />
                                <span class="flex flex-col">
                                    <span class="text-h1 font-semibold tabular">{{ Format::qty($arriving) }}</span>
                                    <span class="text-body font-semibold">{{ trans_choice('home.arriving', $arriving) }}</span>
                                </span>
                                <x-icon name="chevron_right" class="ms-auto shrink-0 rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endif
                </ul>
            </section>
        @endif

        <section aria-labelledby="home-new" class="flex flex-col gap-3">
            <h2 id="home-new" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.start') }}</h2>
            @if ($create->isEmpty())
                <p class="rounded-xl border border-dashed border-default bg-card p-card text-body text-muted">{{ __('home.nothing_to_create') }}</p>
            @else
                <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($create as $type)
                        <li>
                            <a
                                href="{{ $type->route('create') }}"
                                wire:navigate
                                class="flex min-h-20 items-center gap-4 rounded-xl border border-default bg-card p-card shadow-card hover:bg-card-hover"
                            >
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-lg', $type->colours()['soft']])>
                                    <x-icon :name="$type->icon()" @class(['size-7', $type->colours()['icon']]) />
                                </span>
                                <span class="text-h3 font-semibold text-fg">{{ $type->label('new') }}</span>
                                <x-icon name="chevron_right" class="ms-auto shrink-0 text-muted rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
