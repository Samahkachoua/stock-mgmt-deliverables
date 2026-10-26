{{--
    Read-only lines of a document. Columns follow the type:
    transfers show Sent | Received, adjustments In system | Counted | Difference,
    everything else Quantity | Base quantity; Batch and Expiry date appear when
    the type records them and a line has one.
    <x-lines-table :type="$type" :lines="$document['lines']" />
--}}
@props(['type', 'lines'])

@use('App\Support\Format')

@php
    $mode = $type->dispatchReceive() ? 'transfer' : ($type->hasLineColumn('counted') ? 'count' : 'quantity');
    $expiries = collect($lines)->contains(fn ($line) => filled($line['expiry'] ?? null))
        && ($type->hasLineColumn('expiry') || $type->hasLineColumn('expiry_pick') || $mode === 'count');
    $batches = $expiries && collect($lines)->contains(fn ($line) => filled($line['batch'] ?? null));
    $total = collect($lines)->sum(fn ($line) => match ($mode) {
        // While In transit nothing is received yet: total what was sent.
        'transfer' => $line['received'] === null ? $line['base_qty'] : $line['received'] * ($line['base_qty'] / max($line['qty'], 1)),
        // A count line's base quantity is its difference with the system.
        'count' => $line['base_qty'],
        default => $line['base_qty'],
    });
    $num = 'px-cell text-end tabular';
@endphp

<section {{ $attributes->class('overflow-hidden rounded-xl border border-default bg-card shadow-card') }}>
    <div class="flex flex-wrap items-center justify-between gap-3 px-edge py-card-y">
        <h2 class="text-h3 font-semibold text-fg">{{ $type->label('lines_heading') }}</h2>
        <span class="inline-flex h-7 items-center rounded-md bg-card-hover px-2.5 text-small font-semibold text-fg">
            {{ trans_choice('transactions.details.line_count', count($lines), ['count' => Format::qty(count($lines))]) }}
        </span>
    </div>

    <div class="relative overflow-x-auto">
        <table class="cards w-full text-table md:min-w-[640px] xl:min-w-[720px]">
            <thead>
                <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                    <th scope="col" class="w-14 ps-edge text-start">#</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.item') }}</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                    @if ($batches)
                        <th scope="col" class="px-cell text-start">{{ __('transactions.lines.batch') }}</th>
                    @endif
                    @if ($expiries)
                        <th scope="col" class="px-cell text-start">{{ __('transactions.lines.expiry') }}</th>
                    @endif
                    @if ($mode === 'transfer')
                        <th scope="col" class="{{ $num }}">{{ __('transactions.lines.sent') }}</th>
                        <th scope="col" class="{{ $num }} pe-edge">{{ __('transactions.lines.received') }}</th>
                    @elseif ($mode === 'count')
                        <th scope="col" class="{{ $num }}">{{ __('transactions.lines.in_system') }}</th>
                        <th scope="col" class="{{ $num }}">{{ __('transactions.lines.counted') }}</th>
                        <th scope="col" class="{{ $num }} pe-edge">{{ __('transactions.lines.difference') }}</th>
                    @else
                        <th scope="col" class="{{ $num }}">{{ __('transactions.lines.quantity') }}</th>
                        <th scope="col" class="{{ $num }} pe-edge">{{ __('transactions.lines.base_quantity') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-default">
                @foreach ($lines as $i => $line)
                    <tr class="h-row">
                        <td data-card="hide" class="py-cell-y ps-edge text-muted tabular">{{ Format::qty($i + 1) }}</td>
                        <td data-card="title" class="px-cell py-cell-y">
                            <div class="flex flex-wrap items-center gap-2">
                                <bdi class="font-semibold text-fg">{{ $line['name'] }}</bdi>
                                <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $line['code'] }}</span>
                                <x-storage-status :label="$line['storage'] ?? null" />
                            </div>
                            @foreach ($line['details'] ?? [] as $detail)
                                {{-- e.g. which expiry dates a delivery note took (FEFO), or the serial numbers --}}
                                <p class="mt-0.5 text-small text-muted">{{ $detail }}</p>
                            @endforeach
                        </td>
                        <td data-label="{{ __('transactions.lines.unit') }}" class="px-cell text-fg">{{ Format::unit($line['unit']) }}</td>
                        @if ($batches)
                            <td data-label="{{ __('transactions.lines.batch') }}" @if (blank($line['batch'] ?? null)) data-card="hide" @endif class="px-cell text-fg"><span dir="ltr" class="tabular">{{ $line['batch'] ?? '' }}</span></td>
                        @endif
                        @if ($expiries)
                            <td data-label="{{ __('transactions.lines.expiry') }}" @if (blank($line['expiry'] ?? null)) data-card="hide" @endif class="px-cell text-fg">{{ $line['expiry'] ? Format::date(\Illuminate\Support\Carbon::parse($line['expiry'])) : '' }}</td>
                        @endif
                        @if ($mode === 'transfer')
                            <td data-label="{{ __('transactions.lines.sent') }}" class="{{ $num }} font-semibold text-fg">{{ Format::qty($line['qty']) }}</td>
                            <td data-label="{{ __('transactions.lines.received') }}" class="{{ $num }} pe-edge">
                                <span class="inline-flex items-center gap-2">
                                    @if ($line['received'] === null)
                                        <span class="text-muted" aria-hidden="true">&mdash;</span>
                                    @else
                                        @if ($line['received'] != $line['qty'])
                                            <span class="rounded-md border px-1.5 text-small font-semibold {{ config('ui.statuses.rejected') }}"><span dir="ltr">{{ Format::qty($line['received'] - $line['qty']) }}</span></span>
                                        @endif
                                        <span class="font-semibold text-fg">{{ Format::qty($line['received']) }}</span>
                                    @endif
                                </span>
                            </td>
                        @elseif ($mode === 'count')
                            @php($difference = $line['qty'] - $line['system'])
                            <td data-label="{{ __('transactions.lines.in_system') }}" class="{{ $num }} text-muted">{{ Format::qty($line['system']) }}</td>
                            <td data-label="{{ __('transactions.lines.counted') }}" class="{{ $num }} font-semibold text-fg">{{ Format::qty($line['qty']) }}</td>
                            <td data-label="{{ __('transactions.lines.difference') }}" class="{{ $num }} pe-edge">
                                <span @class([
                                    'inline-flex min-w-12 justify-center rounded-md border px-2 py-0.5 text-small font-semibold',
                                    config('ui.statuses.completed') => $difference > 0,
                                    config('ui.statuses.rejected') => $difference < 0,
                                    config('ui.statuses.cancelled') => $difference == 0,
                                ])><span dir="ltr">{{ ($difference > 0 ? '+' : '').Format::qty($difference) }}</span></span>
                            </td>
                        @else
                            <td data-label="{{ __('transactions.lines.quantity') }}" class="{{ $num }} font-semibold text-fg">{{ Format::qty($line['qty']) }}</td>
                            <td data-label="{{ __('transactions.lines.base_quantity') }}" class="{{ $num }} pe-edge text-muted">{{ Format::qtyUnit($line['base_qty'], $line['base_unit']) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="h-row bg-surface">
                    <th scope="row" colspan="2" class="ps-edge text-start font-normal text-muted">{{ __('transactions.details.summary_total') }}</th>
                    <td colspan="{{ ($expiries ? 1 : 0) + ($mode === 'count' ? 4 : 3) }}" class="pe-edge text-end font-semibold text-fg">
                        {{ Format::itemsTotal(count($lines), $total, array_column($lines, 'base_unit')) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
