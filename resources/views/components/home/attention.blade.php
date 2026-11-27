{{--
    Home "Needs your attention": a card per thing waiting (App\Support\Home\StaffHome /
    CustomerHome cards). Cards with nothing waiting drop out and are named in
    one "All caught up on …" line instead; nothing at all waiting: that line only.
    A card opens its list already filtered (Home::open(), no filters in the URL).

    Grid: one per row on a phone, 2 from 640px (an odd last card spans both),
    balanced rows of up to $max from 1024px.
    <x-home.attention :cards="$cards" :max="4" />
--}}
@use('App\Support\Format')

@props(['cards', 'max' => 4])

@php
    $active = collect($cards)->filter(fn (array $card) => $card['count'] > 0)->values();
    $idle = collect($cards)->filter(fn (array $card) => $card['count'] === 0)->values();
    $total = $active->sum('count');

    // Up to $max cards: $max columns, so one card keeps a card's width. More: as few
    // rows as $max columns allow, then spread evenly (5 = 3 + 2). Literal class names for Tailwind.
    $lg = ['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4'];
    $rows = max(1, (int) ceil($active->count() / $max));
    $columns = $lg[$rows === 1 ? $max : min($max, (int) ceil($active->count() / $rows))];
@endphp

@if ($cards !== [])
    <section aria-labelledby="home-attention" data-home-attention class="flex min-w-0 flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 id="home-attention" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.attention') }}</h2>
            @if ($total > 0)
                <x-badge tone="cancelled">{{ trans_choice('home.attention_count', $total, ['count' => Format::qty($total)]) }}</x-badge>
            @endif
        </div>

        @if ($active->isNotEmpty())
            <ul @class(['grid gap-4 sm:grid-cols-2', $columns])>
                @foreach ($active as $card)
                    <li class="min-w-0 sm:last:odd:col-span-2 lg:last:odd:col-span-1">
                        <button
                            type="button"
                            wire:click="open('{{ $card['key'] }}')"
                            wire:loading.attr="disabled"
                            data-attention="{{ $card['key'] }}"
                            aria-label="{{ __('home.waiting_card', ['count' => Format::qty($card['count']), 'label' => $card['label']]) }}"
                            @class([
                                'group flex h-full w-full flex-col gap-3 rounded-2xl border bg-card p-card text-start shadow-card transition duration-150 ease-out hover:shadow-pop motion-safe:hover:-translate-y-0.5 active:translate-y-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand disabled:opacity-60',
                                'border-status-rejected-line' => $card['tone'] === 'rejected',
                                'border-default hover:border-control' => $card['tone'] !== 'rejected',
                            ])
                        >
                            <span class="flex items-start justify-between gap-3">
                                <span @class(['grid size-10 shrink-0 place-items-center rounded-xl border', config('ui.statuses.'.$card['tone'])])>
                                    <x-icon :name="$card['icon']" class="size-5" />
                                </span>
                                <span @class(['rounded-full border px-2 py-0.5 text-small font-semibold', config('ui.statuses.'.$card['tone'])])>{{ $card['chip'] }}</span>
                            </span>
                            <span class="flex min-w-0 flex-col gap-1">
                                <span @class(['text-stat font-bold tabular', $card['tone'] === 'rejected' ? 'text-status-rejected' : 'text-fg'])>{{ Format::qty($card['count']) }}</span>
                                <span class="break-words text-body font-semibold text-fg">{{ $card['label'] }}</span>
                                @if ($card['subtitle'] !== '')
                                    <span class="break-words text-small text-muted">{{ $card['subtitle'] }}</span>
                                @endif
                            </span>
                            <span class="mt-auto inline-flex items-center gap-1 text-small font-semibold text-link group-hover:underline">
                                {{ $card['action'] }}
                                <x-icon name="arrow_forward" class="size-4 rtl:-scale-x-100" />
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($idle->isNotEmpty())
            <p data-caught-up role="status" class="flex items-start gap-2 rounded-xl border border-status-completed-line bg-status-completed-soft px-card py-2.5 text-body text-status-completed">
                <x-icon name="check" class="mt-0.5 size-5 shrink-0" />
                <span>
                    <span class="font-semibold">{{ $active->isEmpty() ? __('home.all_caught_up') : __('home.caught_up_on') }}</span>
                    {{ $idle->pluck('label')->implode(' · ') }}
                </span>
            </p>
        @endif
    </section>
@endif
