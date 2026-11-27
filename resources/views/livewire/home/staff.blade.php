{{--
    Home for staff (App\Livewire\Home, App\Support\Home\StaffHome). Top to bottom,
    content capped at 1280px:
    1. Greeting, today's date, the warehouse filter (users with several).
    2. Needs your attention: a card per thing waiting; the rest "All caught up on …".
    3. Start something new: main button, two secondary, "More".
    4. <Warehouse> at a glance: items in stock, open orders, lots expiring soon.
    5. Today's work queue (wide) beside Recent activity (narrow); stacked below 1024px.
--}}
@use('App\Support\Format')

<div data-home="staff" class="mx-auto flex w-full max-w-7xl flex-col gap-section-lg">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="break-words text-h1 font-bold tracking-tight text-fg">{{ __('home.title', ['greeting' => $greeting, 'name' => auth()->user()->full_name]) }}</h1>
            <p class="mt-1 text-body text-muted">{{ __('home.subtitle_staff', ['date' => Format::date($today)]) }}</p>
        </div>
        @if ($warehouses !== null)
            <div class="w-full sm:w-72">
                <x-home.warehouse-filter :options="$warehouses" />
            </div>
        @endif
    </div>

    {{-- Changing the warehouse reloads the figures: keep them visible, dimmed. --}}
    <div class="flex flex-col gap-section-lg transition-opacity" wire:loading.class="pointer-events-none opacity-50" wire:target="warehouse" wire:loading.attr="aria-busy">
        <x-home.attention :cards="$cards" />

        <x-home.quick-start :actions="$create" />

        @if ($glance !== [])
            <section aria-labelledby="home-glance" class="flex min-w-0 flex-col gap-3">
                <h2 id="home-glance" class="text-body font-semibold uppercase tracking-wide text-muted">{{ __('home.glance.title', ['warehouse' => $warehouseName]) }}</h2>
                <ul @class(['grid gap-4 sm:grid-cols-2', ['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3'][count($glance)] ?? 'lg:grid-cols-3'])>
                    @foreach ($glance as $figure)
                        <li data-glance="{{ $figure['key'] }}" @class([
                            'flex min-w-0 flex-col gap-1 rounded-2xl border p-card shadow-card sm:last:odd:col-span-2 lg:last:odd:col-span-1',
                            'border-status-rejected-line bg-status-rejected-soft' => $figure['alert'],
                            'border-default bg-card' => ! $figure['alert'],
                        ])>
                            <span @class(['text-small font-semibold', $figure['alert'] ? 'text-status-rejected' : 'text-muted'])>{{ $figure['label'] }}</span>
                            <span class="flex flex-wrap items-baseline gap-x-1.5">
                                <span @class(['text-stat font-bold tabular', $figure['alert'] ? 'text-status-rejected' : 'text-fg'])>{{ $figure['value'] }}</span>
                                @if ($figure['unit'] !== '')
                                    <span @class(['text-body', $figure['alert'] ? 'text-status-rejected' : 'text-muted'])>{{ $figure['unit'] }}</span>
                                @endif
                            </span>
                            <span @class(['break-words text-small', $figure['alert'] ? 'text-status-rejected' : 'text-muted'])>{{ $figure['note'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid min-w-0 items-start gap-section-lg lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section aria-labelledby="home-queue" class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-default bg-card shadow-card">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-default px-card py-3">
                    <h2 id="home-queue" class="text-h3 font-semibold text-fg">{{ __('home.queue.title') }}</h2>
                    @if ($ordersKind !== null)
                        <a href="{{ $ordersKind->route() }}" wire:navigate class="text-small font-semibold text-link underline-offset-2 hover:underline">{{ __('home.queue.all_orders') }}</a>
                    @endif
                </div>
                @if ($queue === [])
                    <p class="flex items-center gap-2 p-card text-body text-muted">
                        <x-icon name="check_circle" class="size-5 shrink-0 text-status-completed" />
                        {{ __('home.queue.empty') }}
                    </p>
                @else
                    <ul class="divide-y divide-default">
                        @foreach ($queue as $row)
                            <li>
                                <a
                                    href="{{ $row['url'] }}"
                                    wire:navigate
                                    data-queue-row
                                    class="flex min-h-16 items-center gap-3 px-card py-3 transition-colors duration-150 hover:bg-card-hover active:bg-card-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand"
                                >
                                    <span title="{{ $row['kind']->label('singular') }}" @class(['grid size-9 shrink-0 place-items-center rounded-xl', $row['kind']->colours()['soft']])>
                                        <x-icon :name="$row['kind']->icon()" @class(['size-5', $row['kind']->colours()['icon']]) />
                                    </span>
                                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="font-semibold text-fg tabular" dir="ltr">{{ $row['number'] }}</span>
                                            @if ($row['draft'])
                                                <x-draft-status-badge :code="$row['status']" />
                                            @else
                                                <x-status-badge :code="$row['status']" :type="$row['kind']" />
                                            @endif
                                            @if ($row['late'])
                                                <x-badge tone="rejected"><x-icon name="schedule" class="size-4" />{{ __('home.chips.overdue') }}</x-badge>
                                            @endif
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

            <x-home.recent :rows="$recent" />
        </div>
    </div>
</div>
