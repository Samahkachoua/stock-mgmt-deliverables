{{--
    Home: large links and the user's recent documents only (no charts, no widgets).
    Layout: waiting cards in one column on a phone, 2 from 640px (an odd last
    card spans both), one row from 1024px. Below them, recent documents and the
    actions to start: stacked, side by side from 1024px. Content stops at
    1280px, centred.
--}}
@use('App\Support\Format')

@php
    $heading = 'text-body font-semibold uppercase tracking-wide text-muted';

    // Shared by every card and row: lift on hover, settle when pressed, a visible keyboard ring.
    $interactive = 'transition duration-150 ease-out hover:shadow-pop motion-safe:hover:-translate-y-0.5 active:translate-y-0 active:shadow-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';

    // Waiting cards: from 640px an odd last card fills the row; from 1024px the column count balances the rows instead.
    $cell = 'min-w-0 sm:last:odd:col-span-2 lg:last:odd:col-span-1';

    // Desktop columns for n cards: as few rows as 4 columns allow, then spread evenly (7 = 4 + 3, 5 = 3 + 2).
    // Literal class names, so Tailwind generates them.
    $columns = function (int $count, int $max): string {
        $rows = max(1, (int) ceil($count / $max));

        return ['lg:grid-cols-1', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4'][min($max, max(1, (int) ceil($count / $rows)))];
    };
@endphp

<x-layouts.app :title="__('home.page_title')">
    <div class="mx-auto flex w-full flex-col gap-[calc(var(--section)*2)]">
        <x-page-header icon="home" :title="__('home.title', ['name' => auth()->user()->full_name])" :subtitle="__('home.subtitle')" />

        @if ($approvals->isNotEmpty() || $arriving !== null)
            <section aria-labelledby="home-waiting" class="flex flex-col gap-3">
                <h2 id="home-waiting" class="{{ $heading }}">{{ __('home.waiting') }}</h2>
                @php
                    // Something to do: the status colour (amber to approve, blue on the way),
                    // an accent edge and a shadow. Nothing: a flat, muted card, so the eye goes to the others.
                    $cards = $approvals->map(fn ($approval) => [
                        'href' => $approval['type']->route(), 'navigate' => true, 'icon' => 'verified_user',
                        'count' => $approval['count'], 'label' => $approval['type']->label('to_approve'), 'tone' => 'pending',
                    ]);
                    if ($arriving !== null) {
                        $cards->push(['href' => $transfers->route('incoming'), 'navigate' => false, 'icon' => 'local_shipping', 'count' => $arriving, 'label' => __('home.arriving'), 'tone' => 'transit']);
                    }
                @endphp
                <ul @class(['grid gap-4 sm:grid-cols-2', $columns($cards->count(), 3)])>
                    @foreach ($cards as $card)
                        @php
                            $active = $card['count'] > 0;
                        @endphp
                        <li class="{{ $cell }}">
                            <a
                                href="{{ $card['href'] }}"
                                @if ($card['navigate']) wire:navigate @endif
                                data-waiting="{{ $active ? 'active' : 'idle' }}"
                                aria-label="{{ __('home.waiting_card', ['count' => Format::qty($card['count']), 'label' => $card['label']]) }}"
                                @class([
                                    'flex h-full min-h-22 items-center gap-4 rounded-xl border p-card',
                                    $interactive,
                                    config('ui.statuses.'.$card['tone']).' border-s-4 shadow-card' => $active,
                                    'border-default bg-surface text-muted hover:border-control' => ! $active,
                                ])
                            >
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-lg', 'bg-card' => $active, 'bg-card-hover' => ! $active])>
                                    <x-icon :name="$card['icon']" class="size-6" />
                                </span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span @class(['text-h1 leading-none tabular', 'font-bold' => $active, 'font-semibold' => ! $active])>{{ Format::qty($card['count']) }}</span>
                                    <span class="break-words text-body font-semibold">{{ $card['label'] }}</span>
                                </span>
                                <x-icon name="chevron_right" class="shrink-0 rtl:-scale-x-100" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Side by side from 1024px: recent documents, then what to start. Stacked below that. --}}
        <div class="grid gap-[calc(var(--section)*2)] lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:items-start">
            {{-- The documents the user last worked on (App\Support\Home\RecentActivity): number, status and when. --}}
            <section aria-labelledby="home-recent" class="@container flex min-w-0 flex-col gap-3">
                <h2 id="home-recent" class="{{ $heading }}">{{ __('home.recent.title') }}</h2>
                @if ($recent === [])
                    <p class="rounded-xl border border-dashed border-default bg-card p-card text-body text-muted">{{ __('home.recent.empty') }}</p>
                @else
                    <ul class="divide-y divide-default rounded-xl border border-default bg-card shadow-card">
                        @foreach ($recent as $row)
                            <li class="[&:first-child>a]:rounded-t-xl [&:last-child>a]:rounded-b-xl">
                                <a
                                    href="{{ $row['url'] }}"
                                    wire:navigate
                                    data-recent-row
                                    class="flex min-h-14 items-center gap-3 px-4 py-3 transition-colors duration-150 hover:bg-card-hover active:bg-card-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand"
                                >
                                    <span title="{{ $row['kind']->label('singular') }}" @class(['grid size-10 shrink-0 place-items-center rounded-lg', $row['kind']->colours()['soft']])>
                                        <x-icon :name="$row['kind']->icon()" @class(['size-6', $row['kind']->colours()['icon']]) />
                                    </span>
                                    <span class="sr-only">{{ $row['kind']->label('singular') }}</span>
                                    {{-- The date under the number in a narrow list (beside the other column); at the end of a wide one. --}}
                                    <span class="flex min-w-0 flex-1 flex-col gap-1 @lg:flex-row @lg:items-center @lg:justify-between @lg:gap-3">
                                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="font-semibold text-fg tabular" dir="ltr">{{ $row['number'] }}</span>
                                            @if ($row['draft'])
                                                <x-draft-status-badge :code="$row['status']" />
                                            @else
                                                <x-status-badge :code="$row['status']" :type="$row['kind']" />
                                            @endif
                                        </span>
                                        <time datetime="{{ $row['at']->toIso8601String() }}" class="whitespace-nowrap text-small text-muted tabular">{{ Format::dateTime($row['at']) }}</time>
                                    </span>
                                    <x-icon name="chevron_right" class="shrink-0 text-muted rtl:-scale-x-100" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{--
                Sized by the space this section gets, not the screen (container queries):
                narrow = one list of rows; from 384px = tiles, 2 per row (an odd last one
                spans both); from 576px = balanced rows of up to 4 (7 = 4 + 3).
            --}}
            <section aria-labelledby="home-new" class="@container flex min-w-0 flex-col gap-3">
                <h2 id="home-new" class="{{ $heading }}">{{ __('home.start') }}</h2>
                @if ($create->isEmpty())
                    <p class="rounded-xl border border-dashed border-default bg-card p-card text-body text-muted">{{ __('home.nothing_to_create') }}</p>
                @else
                    @php
                        $rows = max(1, (int) ceil($create->count() / 4));
                        $wide = ['@xl:grid-cols-1', '@xl:grid-cols-1', '@xl:grid-cols-2', '@xl:grid-cols-3', '@xl:grid-cols-4'][min(4, (int) ceil($create->count() / $rows))];
                    @endphp
                    <ul @class(['grid gap-3 @sm:grid-cols-2 @sm:gap-4', $wide])>
                        @foreach ($create as $type)
                            <li class="min-w-0 @sm:last:odd:col-span-2 @xl:last:odd:col-span-1">
                                <a
                                    href="{{ $type->route('create') }}"
                                    wire:navigate
                                    class="group flex h-full min-h-16 items-center gap-4 rounded-xl border border-default bg-card p-card shadow-card hover:border-control @sm:min-h-32 @sm:flex-col @sm:items-stretch @sm:gap-3 {{ $interactive }}"
                                >
                                    <span class="flex items-center justify-between gap-2">
                                        <span @class(['grid size-11 shrink-0 place-items-center rounded-xl transition-transform duration-150 motion-safe:group-hover:scale-105', $type->colours()['soft']])>
                                            <x-icon :name="$type->icon()" @class(['size-6', $type->colours()['icon']]) />
                                        </span>
                                        <x-icon name="chevron_right" class="hidden shrink-0 text-muted transition-colors group-hover:text-fg rtl:-scale-x-100 @sm:block" />
                                    </span>
                                    <span class="min-w-0 flex-1 break-words text-h3 font-semibold text-fg @sm:flex @sm:items-end">{{ $type->label('new') }}</span>
                                    <x-icon name="chevron_right" class="shrink-0 text-muted rtl:-scale-x-100 @sm:hidden" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
