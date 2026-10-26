{{-- One item's stock in one warehouse (docs/design/stitch-exports/item-stock-details). Read-only. --}}
@use('App\Support\Format')

@php
    $badge = function (?\Carbon\CarbonImmutable $expiry) use ($today, $expiringDays) {
        if ($expiry === null) {
            return null;
        }
        $left = \App\Support\Days::between($today, $expiry);

        return match (true) {
            $left < 0 => ['tone' => 'rejected', 'text' => __('reports.item.expired')],
            $left <= $expiringDays => ['tone' => 'pending', 'text' => __('reports.item.expiring', ['days' => Format::qty($expiringDays)])],
            default => ['tone' => 'completed', 'text' => __('reports.item.ok')],
        };
    };
    $nearestBadge = $badge($nearest);
    $tracking = $product->track_serial ? __('reports.item.tracking_serial') : ($product->track_expiry ? __('reports.item.tracking_expiry') : __('reports.item.tracking_none'));
    $details = [
        [__('reports.columns.customer'), $product->customer->name],
        [__('reports.columns.warehouse'), $warehouse->name],
        [__('reports.item.base_unit'), Format::unit($base)],
        [__('reports.item.larger_units'), $larger->isEmpty() ? '—' : $larger->implode(', ')],
    ];
@endphp

<x-layouts.app :title="$product->name()">
    <div class="flex flex-col gap-section">
        <x-page-header icon="inventory_2" :title="$product->name()" :back="route('stock')" :back-label="__('reports.stock_on_hand.title')">
            <x-slot:badge>
                <span dir="ltr" class="tabular rounded bg-card-hover px-2 py-0.5 text-body font-semibold text-muted">{{ $product->sku }}</span>
                <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.completed') }}">
                    {{ __('reports.item.in_stock', ['qty' => $converter->breakdown($total)]) }}
                </span>
            </x-slot:badge>
            @if ($stockCard)
                <x-slot:actions>
                    <a href="{{ $stockCard }}" class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                        <x-icon name="print" class="text-muted" />
                        {{ __('reports.item.stock_card') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-page-header>

        {{-- Item and its stock here. --}}
        <section class="grid gap-x-10 gap-y-3 rounded-xl border border-default bg-card p-card shadow-card md:grid-cols-2">
            <dl class="flex flex-col divide-y divide-default">
                @foreach ($details as [$label, $value])
                    <div class="flex justify-between gap-4 py-2 text-body"><dt class="text-muted">{{ $label }}</dt><dd class="text-end font-semibold text-fg"><bdi>{{ $value }}</bdi></dd></div>
                @endforeach
            </dl>
            <dl class="flex flex-col divide-y divide-default">
                <div class="flex justify-between gap-4 py-2 text-body">
                    <dt class="text-muted">{{ __('reports.item.total') }}</dt>
                    <dd class="text-end font-semibold text-fg tabular">{{ Format::qtyUnit($total, $base) }} <span class="font-normal text-muted">({{ $converter->breakdown($total) }})</span></dd>
                </div>
                <div class="flex justify-between gap-4 py-2 text-body">
                    <dt class="text-muted">{{ __('reports.columns.reserved') }}</dt>
                    <dd class="text-end font-semibold text-fg tabular">{{ $reserved > 0 ? Format::qtyUnit($reserved, $base) : '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2 text-body">
                    <dt class="text-muted">{{ __('reports.columns.available') }}</dt>
                    <dd class="text-end font-semibold text-fg tabular">{{ Format::qtyUnit($total - $reserved, $base) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2 text-body">
                    <dt class="text-muted">{{ __('reports.columns.in_transit') }}</dt>
                    <dd class="text-end font-semibold text-fg tabular">{{ $arriving > 0 ? Format::qtyUnit($arriving, $base) : '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-2 text-body">
                    <dt class="text-muted">{{ __('reports.columns.nearest_expiry') }}</dt>
                    <dd class="flex items-center gap-2 font-semibold text-fg">
                        @if ($nearest)
                            {{ Format::date($nearest) }}
                            <span class="rounded-md border px-2 py-0.5 text-small {{ config('ui.statuses.'.$nearestBadge['tone']) }}">{{ $nearestBadge['text'] }}</span>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4 py-2 text-body"><dt class="text-muted">{{ __('reports.item.tracking') }}</dt><dd class="font-semibold text-fg">{{ $tracking }}</dd></div>
            </dl>
        </section>

        @if ($product->track_expiry)
            <section class="overflow-hidden rounded-xl border border-default bg-card shadow-card">
                <div class="flex flex-wrap items-center justify-between gap-3 px-edge py-card-y">
                    <h2 class="text-h3 font-semibold text-fg">{{ __('reports.item.expiries') }}</h2>
                    <span class="text-body text-muted">{{ trans_choice('reports.item.expiry_count', $expiries->count(), ['count' => Format::qty($expiries->count())]) }}</span>
                </div>
                @if ($expiries->isEmpty())
                    <p class="px-edge pb-card text-body text-muted">{{ __('reports.item.no_stock') }}</p>
                @else
                    <div class="relative overflow-x-auto">
                        <table class="cards w-full text-table md:min-w-[560px]">
                            <thead>
                                <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                                    <th scope="col" class="ps-edge text-start">{{ __('reports.columns.expiry_date') }}</th>
                                    <th scope="col" class="px-cell text-start">{{ __('reports.columns.batch') }}</th>
                                    <th scope="col" class="px-cell text-end">{{ __('reports.columns.qty_base') }}</th>
                                    <th scope="col" class="px-cell text-end">{{ __('reports.columns.qty_larger') }}</th>
                                    <th scope="col" class="pe-edge text-end">{{ __('reports.columns.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-default">
                                @foreach ($expiries as $lot)
                                    @php
                                        $status = $badge($lot['expiry']);
                                    @endphp
                                    <tr class="h-row">
                                        <td data-card="title" class="ps-edge font-semibold text-fg">{{ $lot['expiry'] ? Format::date($lot['expiry']) : '—' }}</td>
                                        <td data-label="{{ __('reports.columns.batch') }}" class="px-cell text-fg"><span dir="ltr" class="tabular">{{ $lot['batch'] ?? __('stock.batch.none') }}</span></td>
                                        <td data-label="{{ __('reports.columns.qty_base') }}" class="px-cell text-end font-semibold text-fg tabular">{{ Format::qtyUnit($lot['qty'], $base) }}</td>
                                        <td data-label="{{ __('reports.columns.qty_larger') }}" class="px-cell text-end text-fg">{{ $converter->breakdown($lot['qty']) }}</td>
                                        <td data-card="badge" class="pe-edge text-end">
                                            @if ($status)
                                                <span class="rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.'.$status['tone']) }}">{{ $status['text'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="h-row bg-surface font-semibold text-fg">
                                    <th scope="row" class="ps-edge text-start">{{ __('reports.item.total') }}</th>
                                    <td class="px-cell text-end tabular">{{ Format::qtyUnit($total, $base) }}</td>
                                    <td class="px-cell text-end">{{ $converter->breakdown($total) }}</td>
                                    <td class="pe-edge"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($product->track_serial)
            <section class="rounded-xl border border-default bg-card p-card shadow-card">
                <h2 class="text-h3 font-semibold text-fg">{{ __('reports.item.serials') }}</h2>
                @if ($serials->isEmpty())
                    <p class="mt-2 text-body text-muted">{{ __('reports.item.no_stock') }}</p>
                @else
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($serials as $serial)
                            <li dir="ltr" class="tabular rounded-md border border-default bg-surface px-2 py-1 text-body text-fg">{{ $serial }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- Who holds part of it: orders and delivery notes not delivered yet. --}}
        @if ($holders->isNotEmpty())
            <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-labelledby="reserved-by">
                <h2 id="reserved-by" class="text-h3 font-semibold text-fg">{{ __('reports.item.reserved_by') }}</h2>
                <ul class="mt-3 flex flex-col divide-y divide-default">
                    @foreach ($holders as $holder)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-body">
                            <a href="{{ $holder['url'] }}" wire:navigate class="inline-flex min-h-control items-center font-semibold text-brand hover:underline"><span dir="ltr" class="tabular">{{ $holder['reference'] }}</span></a>
                            <span class="font-semibold text-fg tabular">{{ Format::qtyUnit($holder['qty'], $base) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <livewire:stock.item-movements :product="$product->id" :warehouse="$warehouse->id" />
    </div>
</x-layouts.app>
