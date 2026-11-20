{{--
    Owner dashboard (App\Livewire\Dashboard): filters, orders by
    customer branch (one bar per branch, split by stage; a row opens the
    Orders list on that branch), delivery discrepancies by branch (split by
    reason; a row opens that report on the branch), lines per warehouse and
    per customer (a customer narrows the whole dashboard to it), and two
    short tables.
    Every chart also opens as a table. The figures load right after the first paint.
--}}
@use('App\Reports\Dashboard')
@use('App\Services\Stock\PostingService')
@use('App\Support\Format')
@use('Carbon\CarbonImmutable')

@php
    $periodText = match (true) {
        $period->from !== '' && $period->to !== '' => __('dashboard.period.range', ['from' => Format::date(CarbonImmutable::parse($period->from)), 'to' => Format::date(CarbonImmutable::parse($period->to))]),
        $period->from !== '' => __('dashboard.period.from', ['from' => Format::date(CarbonImmutable::parse($period->from))]),
        $period->to !== '' => __('dashboard.period.to', ['to' => Format::date(CarbonImmutable::parse($period->to))]),
        default => __('dashboard.period.all'),
    };
    $card = 'flex min-w-0 flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card transition-colors duration-200';
    $heading = 'text-h3 font-bold tracking-tight text-fg';
    $hint = 'text-small text-muted';
    // No customer chosen: the cards list several customers (and name them).
    $noCustomer = $filters['customer'] === '';
    $tableToggle = 'cursor-pointer self-start rounded text-small font-semibold text-link underline-offset-2 hover:underline';
@endphp

<div class="flex flex-col gap-section">
    <x-page-header icon="bar_chart" :title="__('dashboard.title')" :subtitle="__('dashboard.subtitle')" />

    <x-report.filters :definitions="$definitions" :choices="$choices" :values="$filters" :defaults="$defaults" :date-error="$dateError" />

    @if (! $loaded)
        {{-- First paint: grey shapes where the figures will be; the browser asks for them at once. --}}
        <div wire:init="loadRows" aria-busy="true" class="flex flex-col gap-section">
            <span class="sr-only" role="status">{{ __('dashboard.loading') }}</span>
            <div class="grid gap-section xl:grid-cols-2" aria-hidden="true">
                @foreach (range(1, 4) as $i)
                    <div class="h-64 animate-pulse rounded-xl border border-default bg-card shadow-card"></div>
                @endforeach
            </div>
        </div>
    @else
        <x-table-loading target="filters,resetFilters" class="flex flex-col gap-section">
            <div class="flex flex-col gap-section">
                <div class="grid gap-section xl:grid-cols-2">
                    {{-- Orders by branch: one bar per branch, split by how far its orders have got; customer by customer, each on its own scale. --}}
                    @if ($branches !== null)
                        @php
                            $stageColours = ['open' => 'bg-status-draft', 'ready' => 'bg-status-pending', 'on_way' => 'bg-status-transit', 'delivered' => 'bg-status-completed'];
                            // Each customer on its own: its own block, its own scale.
                            $branchGroups = collect($branches)->groupBy('customer_id');
                            $moreBranches = $branchGroups->contains(fn ($rows) => $rows->count() > Dashboard::TOP_BRANCHES);
                        @endphp
                        <section aria-labelledby="dashboard-branches" class="{{ $card }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <h2 id="dashboard-branches" class="{{ $heading }}">{{ __('dashboard.branches.title') }}</h2>
                                <span class="{{ $hint }}">{{ $periodText }}</span>
                            </div>
                            <p class="{{ $hint }}">{{ __('dashboard.branches.hint') }}</p>

                            @if ($branches === [])
                                <p class="rounded-lg border border-dashed border-default p-card text-body text-muted">{{ __('dashboard.branches.empty') }}</p>
                            @else
                                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-small text-muted" aria-label="{{ __('dashboard.branches.legend') }}">
                                    @foreach (Dashboard::ORDER_STAGES as $stage)
                                        <li class="flex items-center gap-1.5">
                                            <span @class(['size-3 shrink-0 rounded-sm', $stageColours[$stage]]) aria-hidden="true"></span>
                                            {{ __('dashboard.branches.stage.'.$stage) }}
                                        </li>
                                    @endforeach
                                </ul>

                                @foreach ($branchGroups as $customerRows)
                                    @php
                                        $busiestBranch = max(1, $customerRows->max('total'));
                                    @endphp
                                    <div data-customer-group="{{ $customerRows->first()['customer_id'] }}" class="flex flex-col">
                                    @if ($noCustomer)
                                        <h3 class="border-b border-default pb-1 text-small font-semibold text-muted">{{ $customerRows->first()['customer'] }}</h3>
                                    @endif
                                <ul class="-mx-card flex flex-col">
                                    @foreach ($allBranches ? $customerRows : $customerRows->take(Dashboard::TOP_BRANCHES) as $row)
                                        <li data-branch="{{ $row['branch_id'] }}">
                                            <button type="button" wire:click="open('orders', '{{ $row['branch_id'] }}')"
                                                class="flex min-h-control w-full flex-col gap-1.5 px-card py-2 text-start hover:bg-card-hover"
                                                aria-label="{{ __('dashboard.branches.open', ['branch' => $row['branch'], 'customer' => $row['customer']]) }}">
                                                <span class="flex w-full items-start gap-3">
                                                    <span class="flex min-w-0 flex-1 flex-col">
                                                        <span class="truncate text-body font-semibold text-fg">{{ $row['branch'] }}</span>
                                                    </span>
                                                    <span class="flex shrink-0 flex-col items-end text-body tabular">
                                                        <span class="font-semibold text-fg">{{ trans_choice('dashboard.branches.orders', $row['total'], ['count' => Format::qty($row['total'])]) }}</span>
                                                        @if ($row['late'] > 0)
                                                            <span class="flex items-center gap-1 text-small font-semibold text-status-rejected">
                                                                <x-icon name="schedule" class="size-4" />
                                                                {{ trans_choice('dashboard.branches.late', $row['late'], ['count' => Format::qty($row['late'])]) }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                    <x-icon name="chevron_right" class="size-5 shrink-0 self-center text-muted rtl:-scale-x-100" />
                                                </span>
                                                <span class="block h-3 w-full overflow-hidden rounded-full bg-card-hover" aria-hidden="true">
                                                    <span class="flex h-full overflow-hidden rounded-full" style="width: {{ max(2, round($row['total'] / $busiestBranch * 100)) }}%" data-bar>
                                                        @foreach (Dashboard::ORDER_STAGES as $stage)
                                                            @if ($row['stages'][$stage] > 0)
                                                                <span @class(['h-full', $stageColours[$stage]]) style="width: {{ round($row['stages'][$stage] / $row['total'] * 100, 2) }}%"
                                                                    title="{{ __('dashboard.branches.stage.'.$stage) }}: {{ Format::qty($row['stages'][$stage]) }}"></span>
                                                            @endif
                                                        @endforeach
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                    </div>
                                @endforeach

                                @if ($moreBranches)
                                    <button type="button" wire:click="$toggle('allBranches')" class="flex h-control items-center gap-1 self-start rounded-lg px-1 text-body font-semibold text-link hover:underline">
                                        {{ $allBranches ? __('dashboard.branches.fewer') : __('dashboard.branches.see_all', ['count' => Format::qty(count($branches))]) }}
                                    </button>
                                @endif

                                <details class="min-w-0">
                                    <summary class="{{ $tableToggle }}">{{ __('dashboard.table.show') }}</summary>
                                    <div class="mt-2 min-w-0 overflow-x-auto">
                                        <table class="w-full text-table">
                                            <thead>
                                                <tr class="text-small font-semibold text-muted">
                                                    <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.branches.branch') }}</th>
                                                    @if ($noCustomer)
                                                        <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.branches.customer') }}</th>
                                                    @endif
                                                    @foreach (Dashboard::ORDER_STAGES as $stage)
                                                        <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.branches.stage.'.$stage) }}</th>
                                                    @endforeach
                                                    <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.branches.total') }}</th>
                                                    <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.branches.late_column') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-default">
                                                @foreach ($branches as $row)
                                                    <tr>
                                                        <th scope="row" class="px-2 py-1 text-start font-normal text-fg">{{ $row['branch'] }}</th>
                                                        @if ($noCustomer)
                                                            <td class="px-2 py-1 text-start text-fg">{{ $row['customer'] }}</td>
                                                        @endif
                                                        @foreach (Dashboard::ORDER_STAGES as $stage)
                                                            <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['stages'][$stage]) }}</td>
                                                        @endforeach
                                                        <td class="px-2 py-1 text-end font-semibold text-fg tabular">{{ Format::qty($row['total']) }}</td>
                                                        <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['late']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endif
                        </section>
                    @endif

                    {{-- Delivery discrepancies by branch: one bar per branch, split by the reason given; customer by customer, each on its own scale. --}}
                    @if ($discrepancies !== null)
                        @php
                            // One colour per reason (PostingService::DELIVERY_REASONS); a reason added later gets a neutral one.
                            $reasonPalette = ['NOT_AVAILABLE' => 'bg-status-rejected', 'REFUSED' => 'bg-status-pending', 'DAMAGED' => 'bg-status-cancelled', 'REPLACED' => 'bg-status-transit'];
                            $reasonColours = collect(PostingService::DELIVERY_REASONS)->mapWithKeys(fn ($reason) => [$reason => $reasonPalette[$reason] ?? 'bg-status-draft'])->all();
                            // Each customer on its own: its own block, its own scale.
                            $discrepancyGroups = collect($discrepancies)->groupBy('customer_id');
                            $moreDiscrepancies = $discrepancyGroups->contains(fn ($rows) => $rows->count() > Dashboard::TOP_BRANCHES);
                        @endphp
                        <section aria-labelledby="dashboard-discrepancies" data-card="discrepancies" class="{{ $card }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <h2 id="dashboard-discrepancies" class="{{ $heading }}">{{ __('dashboard.discrepancies.title') }}</h2>
                                <span class="{{ $hint }}">{{ $periodText }}</span>
                            </div>
                            <p class="{{ $hint }}">{{ __('dashboard.discrepancies.hint') }}</p>

                            @if ($discrepancies === [])
                                <p class="rounded-lg border border-dashed border-default p-card text-body text-muted">{{ __('dashboard.discrepancies.empty') }}</p>
                            @else
                                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-small text-muted" aria-label="{{ __('dashboard.discrepancies.legend') }}">
                                    @foreach ($reasonColours as $reason => $colour)
                                        <li class="flex items-center gap-1.5">
                                            <span @class(['size-3 shrink-0 rounded-sm', $colour]) aria-hidden="true"></span>
                                            {{ __('deliveries.reasons.'.$reason) }}
                                        </li>
                                    @endforeach
                                </ul>

                                @foreach ($discrepancyGroups as $customerRows)
                                    @php
                                        $mostShort = max(1, $customerRows->max('short'));
                                    @endphp
                                    <div data-customer-group="{{ $customerRows->first()['customer_id'] }}" class="flex flex-col">
                                    @if ($noCustomer)
                                        <h3 class="border-b border-default pb-1 text-small font-semibold text-muted">{{ $customerRows->first()['customer'] }}</h3>
                                    @endif
                                <ul class="-mx-card flex flex-col">
                                    @foreach ($allDiscrepancies ? $customerRows : $customerRows->take(Dashboard::TOP_BRANCHES) as $row)
                                        <li data-discrepancy="{{ $row['branch_id'] }}">
                                            <button type="button" wire:click="open('delivery-differences', '{{ $row['branch_id'] }}')"
                                                class="flex min-h-control w-full flex-col gap-1.5 px-card py-2 text-start hover:bg-card-hover"
                                                aria-label="{{ __('dashboard.discrepancies.open', ['branch' => $row['branch'], 'customer' => $row['customer']]) }}">
                                                <span class="flex w-full items-start gap-3">
                                                    <span class="flex min-w-0 flex-1 flex-col">
                                                        <span class="truncate text-body font-semibold text-fg">{{ $row['branch'] }}</span>
                                                    </span>
                                                    <span class="flex shrink-0 flex-col items-end text-body tabular">
                                                        <span class="font-semibold text-fg">{{ trans_choice('dashboard.discrepancies.short', $row['short'], ['count' => Format::qty($row['short'])]) }}</span>
                                                        <span class="text-small text-muted">{{ __('dashboard.discrepancies.rate', ['rate' => Format::qty($row['rate'])]) }}</span>
                                                    </span>
                                                    <x-icon name="chevron_right" class="size-5 shrink-0 self-center text-muted rtl:-scale-x-100" />
                                                </span>
                                                <span class="block h-3 w-full overflow-hidden rounded-full bg-card-hover" aria-hidden="true">
                                                    <span class="flex h-full overflow-hidden rounded-full" style="width: {{ max(2, round($row['short'] / $mostShort * 100)) }}%" data-short-bar>
                                                        @foreach ($reasonColours as $reason => $colour)
                                                            @if ($row['reasons'][$reason] > 0)
                                                                <span @class(['h-full', $colour]) style="width: {{ round($row['reasons'][$reason] / $row['short'] * 100, 2) }}%"
                                                                    title="{{ __('deliveries.reasons.'.$reason) }}: {{ Format::qty($row['reasons'][$reason]) }}"></span>
                                                            @endif
                                                        @endforeach
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                    </div>
                                @endforeach

                                @if ($moreDiscrepancies)
                                    <button type="button" wire:click="$toggle('allDiscrepancies')" class="flex h-control items-center gap-1 self-start rounded-lg px-1 text-body font-semibold text-link hover:underline">
                                        {{ $allDiscrepancies ? __('dashboard.branches.fewer') : __('dashboard.branches.see_all', ['count' => Format::qty(count($discrepancies))]) }}
                                    </button>
                                @endif

                                <details class="min-w-0">
                                    <summary class="{{ $tableToggle }}">{{ __('dashboard.table.show') }}</summary>
                                    <div class="mt-2 min-w-0 overflow-x-auto">
                                        <table class="w-full text-table">
                                            <thead>
                                                <tr class="text-small font-semibold text-muted">
                                                    <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.branches.branch') }}</th>
                                                    @if ($noCustomer)
                                                        <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.branches.customer') }}</th>
                                                    @endif
                                                    @foreach (array_keys($reasonColours) as $reason)
                                                        <th scope="col" class="px-2 py-1 text-end">{{ __('deliveries.reasons.'.$reason) }}</th>
                                                    @endforeach
                                                    <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.discrepancies.short_column') }}</th>
                                                    <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.discrepancies.delivered_column') }}</th>
                                                    <th scope="col" class="px-2 py-1 text-end">%</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-default">
                                                @foreach ($discrepancies as $row)
                                                    <tr>
                                                        <th scope="row" class="px-2 py-1 text-start font-normal text-fg">{{ $row['branch'] }}</th>
                                                        @if ($noCustomer)
                                                            <td class="px-2 py-1 text-start text-fg">{{ $row['customer'] }}</td>
                                                        @endif
                                                        @foreach (array_keys($reasonColours) as $reason)
                                                            <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['reasons'][$reason]) }}</td>
                                                        @endforeach
                                                        <td class="px-2 py-1 text-end font-semibold text-fg tabular">{{ Format::qty($row['short']) }}</td>
                                                        <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['delivered']) }}</td>
                                                        <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['rate']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endif
                        </section>
                    @endif

                    {{-- Lines per warehouse: one bar each, the number written next to it. --}}
                    <section aria-labelledby="dashboard-warehouses" class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h2 id="dashboard-warehouses" class="{{ $heading }}">{{ __('dashboard.warehouses.title') }}</h2>
                            <span class="{{ $hint }}">{{ $periodText }}</span>
                        </div>
                        <p class="{{ $hint }}">{{ __('dashboard.warehouses.hint') }}</p>

                        @php
                            $busiest = max(1, collect($warehouses)->max('lines') ?? 1);
                        @endphp
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

                    {{-- Workload by customer: each customer on its own (A–Z), its bar showing only how its own work splits by kind (each in its type's colour), never scaled against the others. --}}
                    @php
                        $shownCustomers = $allCustomers ? $customers : array_slice($customers, 0, Dashboard::TOP_BRANCHES);
                        $groupColour = fn (string $group) => $customerGroups[$group][0]->colours()['strong'];
                    @endphp
                    <section aria-labelledby="dashboard-customers" data-card="customers" class="{{ $card }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h2 id="dashboard-customers" class="{{ $heading }}">{{ __('dashboard.customers.title') }}</h2>
                            <span class="{{ $hint }}">{{ $periodText }}</span>
                        </div>
                        <p class="{{ $hint }}">{{ __('dashboard.customers.hint') }}</p>

                        @if ($customers === [])
                            <p class="rounded-lg border border-dashed border-default p-card text-body text-muted">{{ __('dashboard.customers.empty') }}</p>
                        @else
                            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-small text-muted" aria-label="{{ __('dashboard.customers.legend') }}">
                                @foreach (array_keys($customerGroups) as $group)
                                    <li class="flex items-center gap-1.5">
                                        <span @class(['size-3 shrink-0 rounded-sm', $groupColour($group)]) aria-hidden="true"></span>
                                        {{ __('dashboard.customers.groups.'.$group) }}
                                    </li>
                                @endforeach
                            </ul>

                            <ul class="-mx-card flex flex-col">
                                @foreach ($shownCustomers as $row)
                                    <li data-customer="{{ $row['customer_id'] }}">
                                        @if ($noCustomer)
                                            <button type="button" wire:click="showCustomer('{{ $row['customer_id'] }}')"
                                                class="flex min-h-control w-full flex-col gap-1.5 px-card py-2 text-start hover:bg-card-hover"
                                                aria-label="{{ __('dashboard.customers.show', ['customer' => $row['customer']]) }}">
                                        @else
                                            <div class="flex min-h-control w-full flex-col gap-1.5 px-card py-2">
                                        @endif
                                            <span class="flex w-full items-start gap-3">
                                                <span class="min-w-0 flex-1 truncate text-body font-semibold text-fg">{{ $row['customer'] }}</span>
                                                <span class="shrink-0 text-body tabular">
                                                    <span class="font-semibold text-fg">{{ trans_choice('dashboard.warehouses.items', $row['lines'], ['count' => Format::qty($row['lines'])]) }}</span>
                                                    <span class="text-muted">· {{ trans_choice('dashboard.warehouses.documents', $row['documents'], ['count' => Format::qty($row['documents'])]) }}</span>
                                                </span>
                                                @if ($noCustomer)
                                                    <x-icon name="chevron_right" class="size-5 shrink-0 self-center text-muted rtl:-scale-x-100" />
                                                @endif
                                            </span>
                                            <span class="block h-3 w-full overflow-hidden rounded-full bg-card-hover" aria-hidden="true">
                                                <span class="flex h-full w-full overflow-hidden rounded-full" data-customer-bar>
                                                    @foreach (array_keys($customerGroups) as $group)
                                                        @if ($row['groups'][$group] > 0)
                                                            <span @class(['h-full', $groupColour($group)]) style="width: {{ round($row['groups'][$group] / $row['lines'] * 100, 2) }}%"
                                                                title="{{ __('dashboard.customers.groups.'.$group) }}: {{ Format::qty($row['groups'][$group]) }}"></span>
                                                        @endif
                                                    @endforeach
                                                </span>
                                            </span>
                                        @if ($noCustomer)
                                            </button>
                                        @else
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if (count($customers) > Dashboard::TOP_BRANCHES)
                                <button type="button" wire:click="$toggle('allCustomers')" class="flex h-control items-center gap-1 self-start rounded-lg px-1 text-body font-semibold text-link hover:underline">
                                    {{ $allCustomers ? __('dashboard.customers.fewer') : __('dashboard.customers.see_all', ['count' => Format::qty(count($customers))]) }}
                                </button>
                            @endif

                            <details class="min-w-0">
                                <summary class="{{ $tableToggle }}">{{ __('dashboard.table.show') }}</summary>
                                <div class="mt-2 min-w-0 overflow-x-auto">
                                    <table class="w-full text-table">
                                        <thead>
                                            <tr class="text-small font-semibold text-muted">
                                                <th scope="col" class="px-2 py-1 text-start">{{ __('dashboard.branches.customer') }}</th>
                                                @foreach (array_keys($customerGroups) as $group)
                                                    <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.customers.groups.'.$group) }}</th>
                                                @endforeach
                                                <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.table.lines') }}</th>
                                                <th scope="col" class="px-2 py-1 text-end">{{ __('dashboard.table.documents') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-default">
                                            @foreach ($customers as $row)
                                                <tr>
                                                    <th scope="row" class="px-2 py-1 text-start font-normal text-fg">{{ $row['customer'] }}</th>
                                                    @foreach (array_keys($customerGroups) as $group)
                                                        <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['groups'][$group]) }}</td>
                                                    @endforeach
                                                    <td class="px-2 py-1 text-end font-semibold text-fg tabular">{{ Format::qty($row['lines']) }}</td>
                                                    <td class="px-2 py-1 text-end text-fg tabular">{{ Format::qty($row['documents']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
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
                                    <button type="button" wire:click="open('{{ $table['key'] }}')" class="flex h-control items-center gap-1 self-start rounded-lg px-1 text-body font-semibold text-link hover:underline">
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
