{{--
    Owner dashboard (App\Livewire\Dashboard): filters, key figures, documents
    per type (one small chart per type, in its colour, so no two colours have
    to be told apart), lines per warehouse, and two short tables. Every chart
    also opens as a table. The figures load right after the first paint.
--}}
@use('App\Reports\Dashboard')
@use('App\Support\Format')
@use('Carbon\CarbonImmutable')

@php
    $periodText = match (true) {
        $period->from !== '' && $period->to !== '' => __('dashboard.period.range', ['from' => Format::date(CarbonImmutable::parse($period->from)), 'to' => Format::date(CarbonImmutable::parse($period->to))]),
        $period->from !== '' => __('dashboard.period.from', ['from' => Format::date(CarbonImmutable::parse($period->from))]),
        $period->to !== '' => __('dashboard.period.to', ['to' => Format::date(CarbonImmutable::parse($period->to))]),
        default => __('dashboard.period.all'),
    };
    $bucketLabel = fn (string $bucket, string $step) => CarbonImmutable::parse($bucket)->locale(app()->getLocale())->isoFormat(match ($step) {
        'day' => 'D MMM',
        'month' => 'MMM YYYY',
        default => 'YYYY',
    });
    $card = 'flex min-w-0 flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card';
    $heading = 'text-h3 font-semibold text-fg';
    $hint = 'text-small text-muted';
    $tableToggle = 'cursor-pointer self-start rounded text-small font-semibold text-brand underline-offset-2 hover:underline';
@endphp

<div class="flex flex-col gap-section">
    <x-page-header icon="bar_chart" :title="__('dashboard.title')" :subtitle="__('dashboard.subtitle')" />

    <x-report.filters :definitions="$definitions" :choices="$choices" :values="$filters" :defaults="$defaults" :date-error="$dateError" />

    @if ($tiles === null)
        {{-- First paint: grey shapes where the figures will be; the browser asks for them at once. --}}
        <div wire:init="loadRows" aria-busy="true" class="flex flex-col gap-section">
            <span class="sr-only" role="status">{{ __('dashboard.loading') }}</span>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5" aria-hidden="true">
                @foreach (range(1, 5) as $i)
                    <div class="flex h-28 flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card">
                        <span class="block h-3 w-2/3 animate-pulse rounded bg-card-hover"></span>
                        <span class="block h-6 w-1/3 animate-pulse rounded bg-card-hover"></span>
                        <span class="block h-3 w-1/2 animate-pulse rounded bg-card-hover"></span>
                    </div>
                @endforeach
            </div>
            <div class="grid gap-section xl:grid-cols-2" aria-hidden="true">
                @foreach (range(1, 2) as $i)
                    <div class="h-64 animate-pulse rounded-xl border border-default bg-card shadow-card"></div>
                @endforeach
            </div>
        </div>
    @else
        <x-table-loading target="filters,resetFilters" class="flex flex-col gap-section">
            <div class="flex flex-col gap-section">
                {{-- Key figures. Each opens what it counts, with the same filters. --}}
                <section aria-labelledby="dashboard-figures" class="flex flex-col gap-3">
                    <h2 id="dashboard-figures" class="sr-only">{{ __('dashboard.tiles.heading') }}</h2>
                    @php
                        $figures = [
                            [
                                'target' => 'pending', 'icon' => 'verified_user', 'label' => __('dashboard.tiles.pending'),
                                'value' => $tiles['pending']['count'], 'when' => __('dashboard.now'),
                                'note' => $tiles['pending']['oldest_days'] !== null
                                    ? trans_choice('dashboard.tiles.pending_oldest', $tiles['pending']['oldest_days'], ['count' => Format::qty($tiles['pending']['oldest_days'])])
                                    : __('dashboard.tiles.pending_none'),
                                'tone' => $tiles['pending']['count'] > 0 ? 'pending' : null,
                                'opens' => $pendingOpens,
                            ],
                            [
                                'target' => 'transfers-in-transit', 'icon' => 'local_shipping', 'label' => __('dashboard.tiles.transit'),
                                'value' => $tiles['transit']['count'], 'when' => __('dashboard.now'),
                                'note' => trans_choice('dashboard.tiles.transit_late', $tiles['transit']['late'], ['count' => Format::qty($tiles['transit']['late']), 'days' => Format::qty(Dashboard::LATE_TRANSIT_DAYS)]),
                                'tone' => $tiles['transit']['late'] > 0 ? 'pending' : null,
                                'opens' => $canReport['transfers-in-transit'],
                            ],
                            [
                                'target' => 'expiry', 'icon' => 'lock_clock', 'label' => __('dashboard.tiles.expiry', ['days' => Format::qty(Dashboard::EXPIRY_DAYS)]),
                                'value' => $tiles['expiry']['count'], 'when' => __('dashboard.now'),
                                'note' => trans_choice('dashboard.tiles.expired', $tiles['expiry']['expired'], ['count' => Format::qty($tiles['expiry']['expired'])]),
                                'tone' => $tiles['expiry']['expired'] > 0 ? 'rejected' : ($tiles['expiry']['count'] > 0 ? 'pending' : null),
                                'opens' => $canReport['expiry'],
                            ],
                            [
                                'target' => 'damage', 'icon' => 'report', 'label' => __('dashboard.tiles.damage'),
                                'value' => $tiles['damage']['lines'], 'when' => $periodText, 'note' => null, 'tone' => null,
                                'opens' => $canReport['damage'],
                            ],
                            [
                                'target' => 'adjustments', 'icon' => 'tune', 'label' => __('dashboard.tiles.adjustments'),
                                'value' => $tiles['adjustments']['lines'], 'when' => $periodText, 'note' => null, 'tone' => null,
                                'opens' => $canReport['adjustments'],
                            ],
                        ];
                    @endphp
                    <ul class="grid grid-cols-2 gap-3 md:grid-cols-3 md:gap-4 xl:grid-cols-5">
                        @foreach ($figures as $figure)
                            @php
                                $tileClass = 'flex h-full w-full flex-col items-start gap-1 rounded-xl border bg-card p-card text-start shadow-card '
                                    .($figure['tone'] ? config('ui.statuses.'.$figure['tone']) : 'border-default text-fg');
                            @endphp
                            <li data-tile="{{ $figure['target'] }}" class="min-w-0">
                                @if ($figure['opens'])
                                    <button type="button" wire:click="open('{{ $figure['target'] }}')" class="{{ $tileClass }} hover:bg-card-hover" aria-label="{{ __('dashboard.tiles.open', ['what' => $figure['label']]) }}">
                                @else
                                    <div class="{{ $tileClass }}">
                                @endif
                                    <span class="flex w-full items-center gap-2 text-small font-semibold">
                                        <x-icon :name="$figure['icon']" class="size-5" />
                                        <span class="min-w-0 flex-1 break-words">{{ $figure['label'] }}</span>
                                        @if ($figure['opens'])
                                            <x-icon name="chevron_right" class="size-5 shrink-0 rtl:-scale-x-100" />
                                        @endif
                                    </span>
                                    <span class="text-h1 font-semibold tabular" data-value>{{ Format::qty($figure['value']) }}</span>
                                    @if ($figure['note'])
                                        <span class="text-small font-semibold">{{ $figure['note'] }}</span>
                                    @endif
                                    <span class="mt-auto text-small opacity-80">{{ $figure['when'] }}</span>
                                @if ($figure['opens'])
                                    </button>
                                @else
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <div class="grid gap-section xl:grid-cols-2">
                    {{-- Documents per type: one small chart per type (its colour names it, its title too). --}}
                    <section aria-labelledby="dashboard-activity" class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h2 id="dashboard-activity" class="{{ $heading }}">{{ __('dashboard.activity.title') }}</h2>
                            <span class="{{ $hint }}">{{ $periodText }}@if ($activity['buckets'] !== []) · {{ __('dashboard.activity.step.'.$activity['step']) }}@endif</span>
                        </div>
                        <p class="{{ $hint }}">{{ __('dashboard.activity.hint') }}</p>

                        @if ($activity['buckets'] === [] || collect($activity['series'])->sum('total') === 0)
                            <p class="rounded-lg border border-dashed border-default p-card text-body text-muted">{{ __('dashboard.activity.empty') }}</p>
                        @else
                            @php($peak = max(1, ...array_map(fn ($series) => max([0, ...array_values($series['counts'])]), array_values($activity['series']))))
                            <ul class="grid gap-4 sm:grid-cols-2">
                                @foreach ($activity['series'] as $code => $series)
                                    <li data-series="{{ $code }}" class="flex flex-col gap-2">
                                        <div class="flex items-center gap-2">
                                            <span @class(['grid size-7 shrink-0 place-items-center rounded-md', $series['type']->colours()['soft']])>
                                                <x-icon :name="$series['type']->icon()" @class(['size-5', $series['type']->colours()['icon']]) />
                                            </span>
                                            <span class="min-w-0 flex-1 truncate text-body font-semibold text-fg">{{ $series['type']->label('title') }}</span>
                                            <span class="text-body font-semibold text-fg tabular">{{ Format::qty($series['total']) }}</span>
                                        </div>
                                        {{-- Same scale for every type, so the charts compare. --}}
                                        <div class="flex h-20 items-end gap-0.5 overflow-hidden border-b border-default" aria-hidden="true">
                                            @foreach ($activity['buckets'] as $bucket)
                                                @php($count = $series['counts'][$bucket] ?? 0)
                                                <div class="flex h-full min-w-0 flex-1 items-end" title="{{ __('dashboard.activity.bar', ['period' => $bucketLabel($bucket, $activity['step']), 'count' => Format::qty($count)]) }}">
                                                    @if ($count > 0)
                                                        <div @class(['w-full rounded-t', $series['type']->colours()['strong']]) style="height: {{ max(4, round($count / $peak * 100)) }}%"></div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="flex justify-between text-small text-muted" aria-hidden="true">
                                <span>{{ $bucketLabel($activity['buckets'][0], $activity['step']) }}</span>
                                <span>{{ $bucketLabel($activity['buckets'][count($activity['buckets']) - 1], $activity['step']) }}</span>
                            </div>

                            <details class="min-w-0">
                                <summary class="{{ $tableToggle }}">{{ __('dashboard.table.show') }}</summary>
                                <div class="mt-2 min-w-0 overflow-x-auto">
                                    <table class="w-full text-table">
                                        <thead>
                                            <tr class="text-small font-semibold text-muted">
                                                <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.table.period') }}</th>
                                                @foreach ($activity['series'] as $series)
                                                    <th scope="col" class="px-2 py-1 text-end">{{ $series['type']->label('title') }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-default">
                                            @foreach ($activity['buckets'] as $bucket)
                                                <tr>
                                                    <th scope="row" class="whitespace-nowrap px-2 py-1 text-start font-normal text-fg">{{ $bucketLabel($bucket, $activity['step']) }}</th>
                                                    @foreach ($activity['series'] as $series)
                                                        <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($series['counts'][$bucket] ?? 0) }}</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    </section>

                    {{-- Lines per warehouse: one bar each, the number written next to it. --}}
                    <section aria-labelledby="dashboard-warehouses" class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h2 id="dashboard-warehouses" class="{{ $heading }}">{{ __('dashboard.warehouses.title') }}</h2>
                            <span class="{{ $hint }}">{{ $periodText }}</span>
                        </div>
                        <p class="{{ $hint }}">{{ __('dashboard.warehouses.hint') }}</p>

                        @php($busiest = max(1, collect($warehouses)->max('lines') ?? 1))
                        <ul class="flex flex-col gap-3">
                            @foreach ($warehouses as $warehouse)
                                <li data-warehouse class="flex flex-col gap-1">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="min-w-0 truncate text-body font-semibold text-fg">{{ $warehouse['name'] }}</span>
                                        <span class="shrink-0 text-body text-fg tabular">
                                            <span class="font-semibold">{{ trans_choice('dashboard.warehouses.items', $warehouse['lines'], ['count' => Format::qty($warehouse['lines'])]) }}</span>
                                            <span class="text-muted">· {{ trans_choice('dashboard.warehouses.documents', $warehouse['documents'], ['count' => Format::qty($warehouse['documents'])]) }}</span>
                                        </span>
                                    </div>
                                    <div class="h-3 overflow-hidden rounded-full bg-card-hover" aria-hidden="true">
                                        @if ($warehouse['lines'] > 0)
                                            <div class="h-full rounded-full bg-brand" style="width: {{ max(2, round($warehouse['lines'] / $busiest * 100)) }}%"></div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                {{-- Two short tables: the first rows of a report; "See all" opens it. --}}
                <div class="grid gap-section xl:grid-cols-2">
                    @foreach ([
                        ['key' => 'expiry', 'data' => $expiring, 'lang' => 'expiring', 'badge' => 'status'],
                        ['key' => 'dormant-stock', 'data' => $dormant, 'lang' => 'dormant', 'badge' => 'days_idle'],
                    ] as $table)
                        <section aria-labelledby="dashboard-{{ $table['key'] }}" data-table="{{ $table['key'] }}" class="{{ $card }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <h2 id="dashboard-{{ $table['key'] }}" class="{{ $heading }}">{{ __('dashboard.'.$table['lang'].'.title') }}</h2>
                                <span class="{{ $hint }}">{{ __('dashboard.now') }}</span>
                            </div>
                            <p class="{{ $hint }}">{{ __('dashboard.'.$table['lang'].'.hint', ['days' => Format::qty($table['data']['days'])]) }}</p>

                            @if ($table['data']['rows']->isEmpty())
                                <p class="rounded-lg border border-dashed border-default p-card text-body text-muted">{{ __('dashboard.'.$table['lang'].'.empty', ['days' => Format::qty($table['data']['days'])]) }}</p>
                            @else
                                <ul class="-mx-card divide-y divide-default border-y border-default">
                                    @foreach ($table['data']['rows'] as $row)
                                        @php($cells = $row['cells'])
                                        <li>
                                            @if ($row['link'])
                                                <a href="{{ $row['link'] }}" wire:navigate class="flex min-h-row items-center gap-3 px-card py-2 hover:bg-card-hover">
                                            @else
                                                <div class="flex min-h-row items-center gap-3 px-card py-2">
                                            @endif
                                                <span class="flex min-w-0 flex-1 flex-col">
                                                    <span class="truncate text-table font-semibold text-fg">{{ $cells['item']['text'] }}</span>
                                                    <span class="truncate text-small text-muted">{{ $cells['customer'] }} · {{ $cells['warehouse'] }}</span>
                                                </span>
                                                <span class="flex shrink-0 flex-col items-end gap-1">
                                                    <span class="text-table font-semibold text-fg tabular">{{ $cells['qty']['text'] }}</span>
                                                    <x-report.cell :cell="$cells[$table['badge']]" />
                                                </span>
                                            @if ($row['link'])
                                                    <x-icon name="chevron_right" class="shrink-0 text-muted rtl:-scale-x-100" />
                                                </a>
                                            @else
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($canReport[$table['key']])
                                    <button type="button" wire:click="open('{{ $table['key'] }}')" class="flex h-control items-center gap-1 self-start rounded-lg px-1 text-body font-semibold text-brand hover:underline">
                                        {{ __('dashboard.see_all', ['count' => Format::qty($table['data']['total'])]) }}
                                        <x-icon name="chevron_right" class="rtl:-scale-x-100" />
                                    </button>
                                @endif
                            @endif
                        </section>
                    @endforeach
                </div>
            </div>
        </x-table-loading>
    @endif
</div>
