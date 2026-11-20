{{--
    Home: large links and the user's recent documents only (no charts, no widgets).
    Top to bottom, full width, content capped at 1280px:
    1. Waiting for you: figure cards (icon box, big number, label), one per
       column on a phone, 2 from 640px (an odd last card spans both), one
       balanced row of up to 4 from 1024px.
    2. Start something new: compact tiles, 2 per row on a phone, balanced rows
       of up to 4 from 768px, one row of up to 7 from 1280px.
    3. Pick up where you left off: one card of rows (number, status, customer,
       what the user did, when).
--}}
@use('App\Support\Format')

@php
    $heading = 'text-body font-semibold uppercase tracking-wide text-muted';

    // Shared by every card and tile: lift on hover, settle when pressed, a visible keyboard ring.
    $interactive = 'transition duration-150 ease-out hover:shadow-pop motion-safe:hover:-translate-y-0.5 active:translate-y-0 active:shadow-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';

    // Columns for n cards at one breakpoint: as few rows as $max columns allow, then spread
    // evenly (7 = 4 + 3, 5 = 3 + 2). Literal class names, so Tailwind generates them.
    $balanced = function (int $count, int $max, array $classes): string {
        $rows = max(1, (int) ceil($count / $max));

        return $classes[min($max, max(1, (int) ceil($count / $rows)))];
    };
    $lg = ['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4'];
    $md = ['', 'md:grid-cols-1', 'md:grid-cols-2', 'md:grid-cols-3', 'md:grid-cols-4'];
    $xl = ['', 'xl:grid-cols-1', 'xl:grid-cols-2', 'xl:grid-cols-3', 'xl:grid-cols-4', 'xl:grid-cols-5', 'xl:grid-cols-6', 'xl:grid-cols-7'];
@endphp

<x-layouts.app :title="__('home.page_title')">
    <div class="mx-auto flex w-full flex-col gap-section-lg">
        <x-page-header icon="home" :breadcrumbs="false" :title="__('home.title', ['name' => auth()->user()->full_name])" :subtitle="__('home.subtitle')" />

        @if ($approvals->isNotEmpty() || $arriving !== null)
            <section aria-labelledby="home-waiting" class="flex flex-col gap-3">
                <h2 id="home-waiting" class="{{ $heading }}">{{ __('home.waiting') }}</h2>
                @php
                    $cards = $approvals->map(fn ($approval) => [
                        'href' => $approval['type']->route(), 'navigate' => true, 'icon' => 'verified_user',
                        'count' => $approval['count'], 'label' => $approval['type']->label('to_approve'), 'tone' => 'pending',
                    ]);
                    if ($arriving !== null) {
                        $cards->push(['href' => $transfers->route('incoming'), 'navigate' => false, 'icon' => 'local_shipping', 'count' => $arriving, 'label' => __('home.arriving'), 'tone' => 'transit']);
                    }
                @endphp
                <ul @class(['grid gap-4 sm:grid-cols-2', $balanced($cards->count(), 4, $lg)])>
                    @foreach ($cards as $card)
                        @php
                            $active = $card['count'] > 0;
                        @endphp
                        <li class="min-w-0 sm:last:odd:col-span-2 lg:last:odd:col-span-1">
                            {{-- Something to do: the icon box takes the status colour (amber to approve, blue on the way). Nothing: grey and muted. --}}
                            <a
                                href="{{ $card['href'] }}"
                                @if ($card['navigate']) wire:navigate @endif
                                data-waiting="{{ $active ? 'active' : 'idle' }}"
                                aria-label="{{ __('home.waiting_card', ['count' => Format::qty($card['count']), 'label' => $card['label']]) }}"
                                class="flex h-full items-center gap-4 rounded-2xl border border-default bg-card p-card shadow-card hover:border-control sm:p-section-lg {{ $interactive }}"
                            >
                                <span @class([
                                    'grid size-14 shrink-0 place-items-center rounded-xl',
                                    config('ui.statuses.'.$card['tone']) => $active,
                                    'bg-card-hover text-muted' => ! $active,
                                ])>
                                    <x-icon :name="$card['icon']" class="size-7" />
                                </span>
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    <span @class(['text-stat font-bold tabular', 'text-fg' => $active])>{{ Format::qty($card['count']) }}</span>
                                    <span class="break-words text-body text-muted">{{ $card['label'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Compact tiles: the type's icon and its name; the section heading says they start something new. --}}
        <section aria-labelledby="home-new" class="flex min-w-0 flex-col gap-3">
            <h2 id="home-new" class="{{ $heading }}">{{ __('home.start') }}</h2>
            @if ($create->isEmpty())
                <p class="rounded-2xl border border-dashed border-default bg-card p-card text-body text-muted">{{ __('home.nothing_to_create') }}</p>
            @else
                <ul @class(['grid grid-cols-2 gap-3', $balanced($create->count(), 4, $md), $balanced($create->count(), 7, $xl)])>
                    @foreach ($create as $type)
                        <li class="min-w-0 last:odd:col-span-2 md:last:odd:col-span-1">
                            <a
                                href="{{ $type->route('create') }}"
                                wire:navigate
                                aria-label="{{ $type->label('new') }}"
                                title="{{ $type->label('new') }}"
                                class="group flex h-full min-h-tile items-center gap-2.5 rounded-xl border border-default bg-card px-3 py-2 shadow-card hover:border-control {{ $interactive }}"
                            >
                                <span @class(['grid size-tile-icon shrink-0 place-items-center rounded-lg transition-transform duration-150 motion-safe:group-hover:scale-105', $type->colours()['soft']])>
                                    <x-icon :name="$type->icon()" @class(['size-5', $type->colours()['icon']]) />
                                </span>
                                <span class="min-w-0 flex-1 break-words text-body font-semibold text-fg">{{ $type->label('singular') }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- The documents the user last worked on (App\Support\Home\RecentActivity). Sized by its own width (container queries). --}}
        <section aria-labelledby="home-recent" class="@container flex min-w-0 flex-col gap-3">
            <h2 id="home-recent" class="{{ $heading }}">{{ __('home.recent.title') }}</h2>
            @if ($recent === [])
                <p class="rounded-2xl border border-dashed border-default bg-card p-card text-body text-muted">{{ __('home.recent.empty') }}</p>
            @else
                <ul class="divide-y divide-default overflow-hidden rounded-2xl border border-default bg-card shadow-card">
                    @foreach ($recent as $row)
                        <li>
                            <a
                                href="{{ $row['url'] }}"
                                wire:navigate
                                data-recent-row
                                class="flex min-h-16 items-center gap-3 px-card py-3 transition-colors duration-150 hover:bg-card-hover active:bg-card-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand @lg:gap-4 @lg:px-section-lg"
                            >
                                <span title="{{ $row['kind']->label('singular') }}" @class(['grid size-10 shrink-0 place-items-center rounded-xl', $row['kind']->colours()['soft']])>
                                    <x-icon :name="$row['kind']->icon()" @class(['size-5', $row['kind']->colours()['icon']]) />
                                </span>
                                {{-- Narrow: number and status, then customer and what the user did, then when. Wide: when moves to the end. --}}
                                <span class="grid min-w-0 flex-1 gap-1 @lg:grid-cols-[minmax(0,1fr)_auto] @lg:items-center @lg:gap-x-4">
                                    <span class="flex min-w-0 flex-col gap-1">
                                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="text-small text-muted">{{ $row['kind']->label('singular') }}</span>
                                            <span class="font-semibold text-fg tabular" dir="ltr">{{ $row['number'] }}</span>
                                            @if ($row['draft'])
                                                <x-draft-status-badge :code="$row['status']" />
                                            @else
                                                <x-status-badge :code="$row['status']" :type="$row['kind']" />
                                            @endif
                                        </span>
                                        <span class="break-words text-small text-muted">{{ __('home.recent.line', ['customer' => $row['customer'], 'event' => __('home.recent.events.'.$row['event'])]) }}</span>
                                    </span>
                                    <time datetime="{{ $row['at']->toIso8601String() }}" class="whitespace-nowrap text-small text-muted tabular @lg:text-end">{{ Format::dateTime($row['at']) }}</time>
                                </span>
                                <x-icon name="chevron_right" class="shrink-0 text-muted rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
