{{--
    Home for a customer user (App\Livewire\Home, App\Support\Home\CustomerHome).
    Top to bottom, content capped at 1280px:
    1. Greeting with the customer's name, the warehouse filter, "New order".
    2. Check your stock: search, Total / By expiry date, recent searches.
    3. Needs your attention: lots expiring soon, orders on their way, orders still editable.
    4. Your stock snapshot: items, lots, lots expiring; lots by time to expiry.
    5. Expiring soonest + Your open orders (wide) beside Recent activity (narrow).
--}}
@use('App\Support\Format')

@php
    $primary = $create->first();
    $others = $create->slice(1, 2);
@endphp

<div data-home="customer" class="mx-auto flex w-full max-w-7xl flex-col gap-section-lg">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="break-words text-h1 font-bold tracking-tight text-fg">{{ __('home.title', ['greeting' => $greeting, 'name' => $customerName]) }}</h1>
            <p class="mt-1 text-body text-muted">{{ __('home.subtitle_customer', ['date' => Format::date($today)]) }}</p>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            @if ($warehouses !== null)
                <div class="w-full sm:w-72">
                    <x-home.warehouse-filter :options="$warehouses" />
                </div>
            @endif
            @foreach ($others as $action)
                <a href="{{ $action['url'] }}" wire:navigate data-quick-start="secondary" class="flex h-control flex-1 items-center justify-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:flex-none">
                    <x-icon :name="$action['icon']" @class(['size-5', $action['kind']?->colours()['icon'] ?? 'text-muted']) />
                    {{ $action['title'] }}
                </a>
            @endforeach
            @if ($primary !== null)
                <a href="{{ $primary['url'] }}" wire:navigate data-quick-start="primary" class="flex h-control flex-1 items-center justify-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-add-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:flex-none">
                    <x-icon name="add" class="size-5" />
                    {{ $primary['title'] }}
                </a>
            @endif
        </div>
    </div>

    <div class="flex flex-col gap-section-lg transition-opacity" wire:loading.class="pointer-events-none opacity-50" wire:target="warehouse" wire:loading.attr="aria-busy">
        @if ($canSearch)
            {{-- Opens the Stock page (or Stock by expiry) already searched: the search goes into its session state. --}}
            <section aria-labelledby="home-check" class="flex min-w-0 flex-col gap-3 rounded-2xl border border-default bg-card p-card shadow-card sm:p-section-lg">
                <h2 id="home-check" class="text-h2 font-bold text-fg">{{ __('home.customer.check.title') }}</h2>
                <form wire:submit="searchStock" class="flex flex-col gap-3 md:flex-row md:items-center">
                    <label class="relative flex min-w-0 flex-1">
                        <span class="sr-only">{{ __('home.customer.check.label') }}</span>
                        <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-muted" />
                        <input
                            type="search"
                            wire:model="stockSearch"
                            data-home-search
                            maxlength="100"
                            autocomplete="off"
                            placeholder="{{ __('home.customer.check.placeholder') }}"
                            class="h-control w-full rounded-lg border border-control bg-card ps-10 pe-3 text-body text-fg placeholder:text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                        >
                    </label>
                    <div class="flex gap-2">
                        @if ($canByExpiry)
                            <div role="radiogroup" aria-label="{{ __('home.customer.check.view') }}" class="flex h-control flex-1 rounded-lg border border-control bg-surface p-0.5 md:flex-none">
                                @foreach (['total', 'expiry'] as $view)
                                    <label class="flex flex-1 cursor-pointer items-center justify-center rounded-md px-3 text-body font-semibold text-muted has-checked:bg-card has-checked:text-fg has-checked:shadow-card has-focus-visible:outline-2 has-focus-visible:outline-brand">
                                        <input type="radio" wire:model="stockView" value="{{ $view }}" class="sr-only">
                                        <span class="whitespace-nowrap">{{ __('home.customer.check.'.$view) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        <button type="submit" wire:loading.attr="disabled" wire:target="searchStock" class="flex h-control shrink-0 items-center justify-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover disabled:opacity-60">
                            <x-spinner wire:loading wire:target="searchStock" class="size-4" />
                            {{ __('home.customer.check.submit') }}
                        </button>
                    </div>
                </form>
                @if ($recentSearches !== [])
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-small text-muted">{{ __('home.customer.check.recent') }}</span>
                        @foreach ($recentSearches as $term)
                            <button type="button" wire:click="searchStock(@js($term))" data-recent-search class="inline-flex h-control max-w-full items-center rounded-full border border-control bg-card px-3 text-body font-semibold text-fg hover:bg-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                <span class="truncate">{{ $term }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <x-home.attention :cards="$cards" :max="3" />

        @if ($snapshot !== null)
            @php
                $lots = array_sum(array_column($snapshot['buckets'], 'lots'));
            @endphp
            <section aria-labelledby="home-snapshot" class="flex min-w-0 flex-col gap-4 rounded-2xl border border-default bg-card p-card shadow-card sm:p-section-lg">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="home-snapshot" class="text-h3 font-semibold text-fg">{{ __('home.customer.snapshot.title') }}</h2>
                    <a href="{{ route('stock') }}" wire:navigate class="text-small font-semibold text-link underline-offset-2 hover:underline">{{ __('home.customer.snapshot.open_stock') }}</a>
                </div>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    @foreach (['items' => $snapshot['items'], 'lots' => $snapshot['lots'], 'expiring' => $snapshot['expiring']] as $key => $value)
                        <div data-snapshot="{{ $key }}" class="flex min-w-0 flex-col gap-1">
                            <dt class="text-small text-muted">{{ __('home.customer.snapshot.'.$key, ['days' => Format::qty(App\Support\Home\CustomerHome::HORIZON_DAYS)]) }}</dt>
                            <dd class="text-stat font-bold text-fg tabular">{{ Format::qty($value) }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($lots > 0)
                    <div class="flex flex-col gap-2">
                        <h3 class="text-small font-semibold text-fg">{{ __('home.customer.snapshot.by_expiry') }}</h3>
                        {{-- Lots, not quantities: different items never add up. Each segment also has its number in the legend (not colour alone). --}}
                        <div class="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-surface" role="img" aria-label="{{ collect($snapshot['buckets'])->map(fn ($bucket) => $bucket['label'].': '.Format::qty($bucket['lots']))->implode(', ') }}">
                            @foreach ($snapshot['buckets'] as $bucket)
                                @if ($bucket['lots'] > 0)
                                    <span @class(['h-full', config('ui.badges.'.$bucket['tone'])]) style="width: {{ round($bucket['lots'] / $lots * 100, 2) }}%"></span>
                                @endif
                            @endforeach
                        </div>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($snapshot['buckets'] as $bucket)
                                <li data-bucket="{{ $bucket['key'] }}" class="flex items-center gap-1.5 text-small text-muted">
                                    <span @class(['size-2.5 shrink-0 rounded-sm', config('ui.badges.'.$bucket['tone'])])></span>
                                    {{ $bucket['label'] }} ·
                                    <span class="font-semibold text-fg tabular">{{ trans_choice('home.customer.snapshot.lot_count', $bucket['lots'], ['count' => Format::qty($bucket['lots'])]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid min-w-0 items-start gap-section-lg lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="flex min-w-0 flex-col gap-section-lg">
                @if ($snapshot !== null)
                    <section aria-labelledby="home-soonest" class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-default bg-card shadow-card">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default px-card py-3">
                            <h2 id="home-soonest" class="text-h3 font-semibold text-fg">{{ __('home.customer.soonest.title') }}</h2>
                            @if ($canByExpiry)
                                <a href="{{ route('stock-by-expiry') }}" wire:navigate class="text-small font-semibold text-link underline-offset-2 hover:underline">{{ __('home.customer.soonest.all') }}</a>
                            @endif
                        </div>
                        @if ($soonest === [])
                            <p class="p-card text-body text-muted">{{ __('home.customer.soonest.empty') }}</p>
                        @else
                            <table class="w-full text-table max-md:hidden">
                                <thead>
                                    <tr class="border-b border-default bg-card text-start">
                                        <th scope="col" class="px-card py-2 text-start font-semibold text-fg">{{ __('home.customer.soonest.item') }}</th>
                                        <th scope="col" class="px-3 py-2 text-start font-semibold text-fg">{{ __('home.customer.soonest.batch') }}</th>
                                        <th scope="col" class="px-3 py-2 text-end font-semibold text-fg">{{ __('home.customer.soonest.qty') }}</th>
                                        <th scope="col" class="px-card py-2 text-start font-semibold text-fg">{{ __('home.customer.soonest.expires') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-default">
                                    @foreach ($soonest as $lot)
                                        <tr data-soonest-row>
                                            <td class="px-card py-2.5 font-semibold text-fg">{{ $lot['item'] }}</td>
                                            <td class="px-3 py-2.5 text-muted"><span dir="ltr" class="tabular">{{ $lot['batch'] !== '' ? $lot['batch'] : __('home.customer.soonest.no_batch') }}</span></td>
                                            <td class="whitespace-nowrap px-3 py-2.5 text-end text-fg tabular">{{ $lot['qty'] }}</td>
                                            <td class="px-card py-2.5"><x-badge :tone="$lot['tone'] === 'rejected' ? 'rejected' : 'pending'">{{ __('home.customer.soonest.left', ['date' => $lot['date'], 'days' => trans_choice('home.customer.soonest.days', $lot['left'], ['count' => Format::qty($lot['left'])])]) }}</x-badge></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            {{-- Phone: one card per lot. --}}
                            <ul class="divide-y divide-default md:hidden">
                                @foreach ($soonest as $lot)
                                    <li class="flex flex-col gap-1.5 px-card py-3">
                                        <span class="flex items-start justify-between gap-3">
                                            <span class="min-w-0 break-words font-semibold text-fg">{{ $lot['item'] }}</span>
                                            <span class="whitespace-nowrap text-fg tabular">{{ $lot['qty'] }}</span>
                                        </span>
                                        <span class="flex flex-wrap items-center gap-2 text-small text-muted">
                                            <span dir="ltr" class="tabular">{{ $lot['batch'] !== '' ? $lot['batch'] : __('home.customer.soonest.no_batch') }}</span>
                                            <x-badge :tone="$lot['tone'] === 'rejected' ? 'rejected' : 'pending'">{{ __('home.customer.soonest.left', ['date' => $lot['date'], 'days' => trans_choice('home.customer.soonest.days', $lot['left'], ['count' => Format::qty($lot['left'])])]) }}</x-badge>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                @if ($orders !== null)
                    <section aria-labelledby="home-orders" class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-default bg-card shadow-card">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default px-card py-3">
                            <h2 id="home-orders" class="text-h3 font-semibold text-fg">{{ __('home.customer.orders.title') }}</h2>
                            <a href="{{ $orders->route() }}" wire:navigate class="text-small font-semibold text-link underline-offset-2 hover:underline">{{ __('home.customer.orders.all') }}</a>
                        </div>
                        @if ($openOrders === [])
                            <p class="p-card text-body text-muted">{{ __('home.customer.orders.empty') }}</p>
                        @else
                            <ul class="divide-y divide-default">
                                @foreach ($openOrders as $row)
                                    <li>
                                        <a href="{{ $row['url'] }}" wire:navigate data-open-order class="flex min-h-16 items-center gap-3 px-card py-3 transition-colors duration-150 hover:bg-card-hover active:bg-card-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand">
                                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                                <span class="flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold text-fg tabular" dir="ltr">{{ $row['number'] }}</span>
                                                    <x-draft-status-badge :code="$row['status']" />
                                                </span>
                                                <span class="break-words text-small text-muted">{{ $row['line'] }}</span>
                                            </span>
                                            <x-icon name="chevron_right" class="shrink-0 text-muted rtl:-scale-x-100" />
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif
            </div>

            <x-home.recent :rows="$recent" />
        </div>
    </div>
</div>
