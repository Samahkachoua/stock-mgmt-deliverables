{{--
    Lines of a document on the A4 print: a fully ruled table with a blue
    header (SKU | Description | Unit of measure | Expiry date | Qty).
    Transfers show Sent | Received, adjustments In system | Counted | Difference
    in place of Qty. Expiry date appears when the type records it and a line has one.
    A draft passes its warehouse groups instead (no type): plain Qty, and a
    warehouse row before each group when there are several.
    <x-print-lines-table :type="$type" :lines="$document['lines']" />
    <x-print-lines-table :groups="$draft['groups']" />
--}}
@props(['type' => null, 'lines' => null, 'groups' => null])

@use('App\Support\Format')

@php
    $groups ??= [['warehouse' => null, 'lines' => $lines]];
    $lines = collect($groups)->flatMap(fn ($group) => $group['lines'])->all();
    $mode = $type === null ? 'quantity' : ($type->dispatchReceive() ? 'transfer' : ($type->hasLineColumn('counted') ? 'count' : 'quantity'));
    $expiries = collect($lines)->contains(fn ($line) => filled($line['expiry'] ?? null))
        && ($type === null || $type->hasLineColumn('expiry') || $type->hasLineColumn('expiry_pick') || $mode === 'count');
    $columns = 3 + ($expiries ? 1 : 0) + ($mode === 'count' ? 3 : ($mode === 'transfer' ? 2 : 1));
    $total = collect($lines)->sum(fn ($line) => match ($mode) {
        'transfer' => $line['received'] === null ? $line['base_qty'] : $line['received'] * ($line['base_qty'] / max($line['qty'], 1)),
        default => $line['base_qty'],
    });
    $th = 'border border-black px-2 py-1.5 text-center font-bold underline';
    $td = 'border border-black px-2 py-1';
    $num = $td.' text-center tabular font-bold';
@endphp

<table {{ $attributes->class('print-lines w-full border-collapse border-2 border-black text-table') }}>
    <thead>
        <tr class="bg-[var(--print-head)] text-on-brand">
            <th scope="col" class="{{ $th }}">{{ __('print.lines.sku') }}</th>
            <th scope="col" class="{{ $th }}">{{ __('print.lines.description') }}</th>
            <th scope="col" class="{{ $th }}">{{ __('print.lines.unit') }}</th>
            @if ($expiries)
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.expiry') }}</th>
            @endif
            @if ($mode === 'transfer')
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.sent') }}</th>
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.received') }}</th>
            @elseif ($mode === 'count')
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.in_system') }}</th>
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.counted') }}</th>
                <th scope="col" class="{{ $th }}">{{ __('transactions.lines.difference') }}</th>
            @else
                <th scope="col" class="{{ $th }}">{{ __('print.lines.qty') }}</th>
            @endif
        </tr>
    </thead>
    @foreach ($groups as $group)
    <tbody>
        @if (count($groups) > 1)
            <tr>
                <th scope="rowgroup" colspan="{{ $columns }}" class="{{ $td }} text-start font-bold"><bdi>{{ $group['warehouse'] }}</bdi></th>
            </tr>
        @endif
        @foreach ($group['lines'] as $line)
            <tr>
                <td class="{{ $td }} text-center"><span dir="ltr" class="tabular">{{ $line['code'] }}</span></td>
                <td class="{{ $td }} text-start">
                    <bdi>{{ $line['name'] }}</bdi>
                    @if (filled($line['storage'] ?? null))
                        <p class="text-small">{{ $line['storage'] }}</p>
                    @endif
                    @foreach ($line['details'] ?? [] as $detail)
                        <p class="text-small">{{ $detail }}</p>
                    @endforeach
                </td>
                <td class="{{ $td }} text-center">{{ Format::unit($line['unit']) }}</td>
                @if ($expiries)
                    <td class="{{ $td }} text-center tabular">{{ $line['expiry'] ? Format::date(\Illuminate\Support\Carbon::parse($line['expiry'])) : '' }}</td>
                @endif
                @if ($mode === 'transfer')
                    <td class="{{ $num }}">{{ Format::qty($line['qty']) }}</td>
                    <td class="{{ $num }}">{{ $line['received'] === null ? '—' : Format::qty($line['received']) }}</td>
                @elseif ($mode === 'count')
                    @php($difference = $line['qty'] - $line['system'])
                    <td class="{{ $td }} text-center tabular">{{ Format::qty($line['system']) }}</td>
                    <td class="{{ $num }}">{{ Format::qty($line['qty']) }}</td>
                    <td class="{{ $num }}"><span dir="ltr">{{ ($difference > 0 ? '+' : '').Format::qty($difference) }}</span></td>
                @else
                    <td class="{{ $num }}">{{ Format::qty($line['qty']) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
    @endforeach
    <tfoot>
        <tr>
            <th scope="row" colspan="{{ 3 + ($expiries ? 1 : 0) }}" class="{{ $td }} text-end">{{ __('transactions.details.summary_total') }}</th>
            <td colspan="{{ $mode === 'count' ? 3 : ($mode === 'transfer' ? 2 : 1) }}" class="{{ $num }}">
                {{ Format::itemsTotal(count($lines), $total, array_column($lines, 'base_unit')) }}
            </td>
        </tr>
    </tfoot>
</table>
