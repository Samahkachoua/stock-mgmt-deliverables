{{--
    Read-only lines of a draft, grouped by warehouse (each group becomes one
    receipt / issue when the draft is confirmed). `line-errors`: draft line id =>
    why it can't be confirmed, shown under the item.
    <x-draft-lines :groups="$draft['groups']" :line-errors="$lineErrors" />
--}}
@props(['groups', 'lineErrors' => []])

@use('App\Support\Format')

@php
    $lines = collect($groups)->flatMap(fn ($group) => $group['lines']);
    $expiries = $lines->contains(fn ($line) => filled($line['expiry']));
    $num = 'px-cell text-end tabular';
    $columns = 4 + ($expiries ? 1 : 0);
    $number = 0;
@endphp

<section {{ $attributes->class('overflow-hidden rounded-xl border border-default bg-card shadow-card') }}>
    <div class="flex flex-wrap items-center justify-between gap-3 px-edge py-card-y">
        <h2 class="text-h3 font-semibold text-fg">{{ __('drafts.details.lines_heading') }}</h2>
        <span class="inline-flex h-7 items-center rounded-md bg-card-hover px-2.5 text-small font-semibold text-fg">
            {{ trans_choice('transactions.details.line_count', $lines->count(), ['count' => Format::qty($lines->count())]) }}
        </span>
    </div>

    <div class="relative overflow-x-auto">
        <table class="cards w-full text-table md:min-w-[640px] xl:min-w-[720px]">
            <thead>
                <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                    <th scope="col" class="w-14 ps-edge text-start">#</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.item') }}</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                    @if ($expiries)
                        <th scope="col" class="px-cell text-start">{{ __('transactions.lines.expiry') }}</th>
                    @endif
                    <th scope="col" class="{{ $num }}">{{ __('transactions.lines.quantity') }}</th>
                    <th scope="col" class="{{ $num }} pe-edge">{{ __('transactions.lines.base_quantity') }}</th>
                </tr>
            </thead>
            @foreach ($groups as $group)
                <tbody class="divide-y divide-default border-t border-default">
                    {{-- One receipt / issue per warehouse. --}}
                    <tr class="bg-surface">
                        <th scope="rowgroup" colspan="{{ $columns + 1 }}" class="px-edge py-2 text-start text-body font-semibold text-fg">
                            <span class="inline-flex items-center gap-2">
                                <x-icon name="warehouse" class="size-4 text-muted" />
                                <bdi>{{ $group['warehouse'] }}</bdi>
                                <span class="font-normal text-muted">· {{ trans_choice('transactions.summary.items', count($group['lines']), ['count' => Format::qty(count($group['lines']))]) }}</span>
                            </span>
                        </th>
                    </tr>
                    @foreach ($group['lines'] as $line)
                        @php($number++)
                        <tr class="h-row" @isset($lineErrors[$line['id']]) aria-invalid="true" @endisset>
                            <td data-card="hide" class="py-cell-y ps-edge text-muted tabular">{{ Format::qty($number) }}</td>
                            <td data-card="title" class="px-cell py-cell-y">
                                <div class="flex flex-wrap items-center gap-2">
                                    <bdi class="font-semibold text-fg">{{ $line['name'] }}</bdi>
                                    <span dir="ltr" class="tabular rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold tracking-wide text-muted">{{ $line['code'] }}</span>
                                    <x-storage-status :label="$line['storage'] ?? null" />
                                </div>
                                @foreach ($line['details'] as $detail)
                                    <p class="mt-0.5 text-small text-muted"><span dir="ltr">{{ $detail }}</span></p>
                                @endforeach
                                @isset($lineErrors[$line['id']])
                                    <p class="mt-1 text-small text-danger" role="alert">{{ $lineErrors[$line['id']] }}</p>
                                @endisset
                            </td>
                            <td data-label="{{ __('transactions.lines.unit') }}" class="px-cell text-fg">{{ Format::unit($line['unit']) }}</td>
                            @if ($expiries)
                                <td data-label="{{ __('transactions.lines.expiry') }}" @if (blank($line['expiry'])) data-card="hide" @endif class="px-cell text-fg">{{ $line['expiry'] ? Format::date(\Illuminate\Support\Carbon::parse($line['expiry'])) : '' }}</td>
                            @endif
                            <td data-label="{{ __('transactions.lines.quantity') }}" class="{{ $num }} font-semibold text-fg">{{ Format::qty($line['qty']) }}</td>
                            <td data-label="{{ __('transactions.lines.base_quantity') }}" class="{{ $num }} pe-edge text-muted">{{ Format::qtyUnit($line['base_qty'], $line['base_unit']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
            <tfoot>
                <tr class="h-row bg-surface">
                    <th scope="row" colspan="2" class="ps-edge text-start font-normal text-muted">{{ __('transactions.details.summary_total') }}</th>
                    <td colspan="{{ $columns - 1 }}" class="pe-edge text-end font-semibold text-fg">
                        {{ Format::itemsTotal($lines->count(), $lines->sum('base_qty'), $lines->pluck('base_unit')->all()) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>
