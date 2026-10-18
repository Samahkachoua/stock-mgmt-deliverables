{{-- The movements of one item in one warehouse, newest first, between two dates (App\Livewire\Stock\ItemMovements). --}}
@use('App\Support\Format')

<section class="overflow-hidden rounded-xl border border-default bg-card shadow-card">
    <div class="flex flex-wrap items-end justify-between gap-4 px-edge py-card-y">
        <div>
            <h2 class="text-h3 font-semibold text-fg">{{ __('reports.item.movements', ['count' => Format::qty($limit)]) }}</h2>
            <p class="text-body text-muted">{{ __('reports.item.movements_hint') }}</p>
        </div>
        <div class="grid w-full grid-cols-1 gap-3 md:w-auto md:grid-cols-[13rem_13rem_auto] print:hidden" role="search">
            <x-date-range :error="$dateError" />
            <button type="button" wire:click="resetFilters" class="flex h-control shrink-0 items-center justify-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                <x-icon name="filter_alt_off" class="text-muted" />
                {{ __('transactions.filters.reset') }}
            </button>
        </div>
    </div>

    <x-table-loading target="dateFrom, dateTo, resetFilters">
        @if ($dateError)
            <p class="px-edge pb-card text-body text-danger" role="alert">{{ __('feedback.date_order') }}</p>
        @elseif ($movements->isEmpty())
            <p class="px-edge pb-card text-body text-muted">{{ __($filtered ? 'reports.item.no_movements_in_dates' : 'reports.item.no_movements') }}</p>
        @else
            <div class="relative overflow-x-auto">
                <table class="cards w-full text-table xl:min-w-[760px]">
                    <thead>
                        <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                            <th scope="col" class="ps-edge text-start">{{ __('reports.columns.date') }}</th>
                            <th scope="col" class="px-cell text-start">{{ __('reports.columns.type') }}</th>
                            <th scope="col" class="px-cell text-start">{{ __('reports.columns.reference') }}</th>
                            <th scope="col" data-priority="2" class="px-cell text-start">{{ __('reports.columns.warehouse') }}</th>
                            <th scope="col" class="px-cell text-end">{{ __('reports.columns.quantity_change') }}</th>
                            <th scope="col" class="pe-edge text-end">{{ __('reports.columns.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-default">
                        @foreach ($movements as $movement)
                            <tr class="h-row" wire:key="movement-{{ $loop->index }}-{{ $movement['reference'] }}">
                                <td data-label="{{ __('reports.columns.date') }}" class="whitespace-nowrap ps-edge text-fg tabular">{{ Format::dateTime($movement['at']) }}</td>
                                <td data-card="badge" class="px-cell"><x-type-badge :code="$movement['type']" /></td>
                                <td data-card="title" class="px-cell">
                                    @if ($movement['link'])
                                        <a href="{{ $movement['link'] }}" wire:navigate dir="ltr" class="tabular font-semibold text-fg underline-offset-2 hover:underline">{{ $movement['reference'] }}</a>
                                    @else
                                        <span dir="ltr" class="tabular font-semibold text-fg">{{ $movement['reference'] }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ __('reports.columns.warehouse') }}" data-priority="2" class="px-cell text-fg"><bdi>{{ $movement['route'] ?? $warehouseName }}</bdi></td>
                                <td data-label="{{ __('reports.columns.quantity_change') }}" @class(['whitespace-nowrap px-cell text-end font-semibold tabular', 'text-status-completed' => $movement['qty'] > 0, 'text-status-rejected' => $movement['qty'] < 0])>
                                    <span dir="ltr">{{ ($movement['qty'] > 0 ? '+' : '-').Format::qtyUnit(abs($movement['qty']), $base) }}</span>
                                </td>
                                <td data-label="{{ __('reports.columns.balance') }}" class="whitespace-nowrap pe-edge text-end font-semibold text-fg tabular">{{ Format::qtyUnit($movement['balance'], $base) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-table-loading>
</section>
