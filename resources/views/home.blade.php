{{-- Home: large links only (no charts, no widgets). --}}
@use('App\Support\Format')

<x-layouts.app :title="__('home.page_title')">
    <div class="flex flex-col gap-section">
        <x-page-header icon="home" :title="__('home.title', ['name' => auth()->user()->full_name])" :subtitle="__('home.subtitle')" />

        @if ($approvals->isNotEmpty() || $arriving !== null)
            <section aria-labelledby="home-waiting" class="flex flex-col gap-3">
                <h2 id="home-waiting" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.waiting') }}</h2>
                @php
                    // Something to do: the status colour (amber to approve, blue on the way)
                    // and a shadow. Nothing: a flat, muted card, so the eye goes to the others.
                    $cards = $approvals->map(fn ($approval) => [
                        'href' => $approval['type']->route(), 'navigate' => true, 'icon' => 'verified_user',
                        'count' => $approval['count'], 'label' => $approval['type']->label('to_approve'), 'tone' => 'pending',
                    ]);
                    if ($arriving !== null) {
                        $cards->push(['href' => $transfers->route('incoming'), 'navigate' => false, 'icon' => 'local_shipping', 'count' => $arriving, 'label' => __('home.arriving'), 'tone' => 'transit']);
                    }
                @endphp
                <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($cards as $card)
                        <li>
                            <a
                                href="{{ $card['href'] }}"
                                @if ($card['navigate']) wire:navigate @endif
                                data-waiting="{{ $card['count'] > 0 ? 'active' : 'idle' }}"
                                @class([
                                    'flex min-h-20 items-center gap-4 rounded-xl border p-card',
                                    config('ui.statuses.'.$card['tone']).' shadow-card hover:shadow-pop' => $card['count'] > 0,
                                    'border-default bg-surface text-muted hover:bg-card-hover' => $card['count'] === 0,
                                ])
                            >
                                <x-icon :name="$card['icon']" class="size-8 shrink-0" />
                                <span class="flex flex-col">
                                    <span @class(['text-h1 tabular', 'font-bold' => $card['count'] > 0, 'font-semibold' => $card['count'] === 0])>{{ Format::qty($card['count']) }}</span>
                                    <span class="text-body font-semibold">{{ $card['label'] }}</span>
                                </span>
                                <x-icon name="chevron_right" class="ms-auto shrink-0 rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
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
