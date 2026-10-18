@use('App\Support\Format')

@php
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg focus:outline-none focus:ring-2';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
@endphp

{{-- Receiving at the destination: Sent | Received (editable) | reason when short. --}}
<section class="overflow-hidden rounded-xl border border-default bg-card shadow-card" aria-labelledby="receive-heading">
    <div class="flex flex-wrap items-start justify-between gap-3 px-edge py-card-y">
        <div>
            <h2 id="receive-heading" class="text-h3 font-semibold text-fg">{{ __('transfers.receive.heading') }}</h2>
            <p class="text-body text-muted">{{ __('transfers.receive.hint') }}</p>
        </div>
    </div>

    {{-- Read-only from the moment "Confirm receipt" is clicked until the answer is back. --}}
    <fieldset wire:loading.attr="disabled" wire:target="confirm" class="min-w-0">
    <legend class="sr-only">{{ __('transfers.receive.heading') }}</legend>
    <div class="relative overflow-x-auto">
        <table data-form class="cards w-full text-table md:min-w-[672px] xl:min-w-[880px]">
            <thead>
                <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                    <th scope="col" class="w-14 ps-edge text-start">#</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.item') }}</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                    <th scope="col" class="px-cell text-end">{{ __('transactions.lines.sent') }}</th>
                    <th scope="col" class="w-44 px-cell text-start xl:w-64">{{ __('transfers.receive.received') }}</th>
                    <th scope="col" class="w-52 pe-edge text-start xl:w-72">{{ __('transfers.receive.reason') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-default">
                @foreach ($document['lines'] as $i => $line)
                    @php
                        $id = $line['id'];
                        $row = $check['rows'][$id];
                    @endphp
                    <tr wire:key="receive-{{ $id }}" class="align-top">
                        <td data-card="index" class="py-cell-y ps-edge text-muted tabular"><span class="flex items-center md:h-control">{{ Format::qty($i + 1) }}</span></td>
                        <td class="px-cell py-cell-y">
                            <bdi class="font-semibold text-fg">{{ $line['name'] }}</bdi>
                            <span dir="ltr" class="tabular ms-1 rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold text-muted">{{ $line['code'] }}</span>
                            @foreach ($line['details'] as $detail)
                                <p class="mt-0.5 text-small text-muted">{{ $detail }}</p>
                            @endforeach
                        </td>
                        <td data-card="half" data-label="{{ __('transactions.lines.unit') }}" class="px-cell py-cell-y text-fg"><span class="inline-flex items-center md:h-control">{{ Format::unit($line['unit']) }}</span></td>
                        <td data-card="half" data-label="{{ __('transactions.lines.sent') }}" class="px-cell py-cell-y text-end font-semibold text-fg tabular"><span class="inline-flex items-center md:h-control">{{ Format::qty($line['qty']) }}</span></td>
                        <td data-label="{{ __('transfers.receive.received') }}" class="px-cell py-cell-y">
                            @if ($line['serials'] !== [])
                                {{-- Serial items: untick what did not arrive. --}}
                                <fieldset class="max-h-40 overflow-y-auto rounded-lg border {{ $row['error'] ? 'border-danger' : 'border-control' }} bg-card px-3 py-1">
                                    <legend class="sr-only">{{ __('transfers.receive.serials_hint') }}</legend>
                                    @foreach ($line['serials'] as $serial)
                                        <label wire:key="receive-{{ $id }}-{{ $serial }}" class="flex min-h-control cursor-pointer items-center gap-3">
                                            <input type="checkbox" wire:model.live="serials.{{ $id }}" value="{{ $serial }}" class="size-5 shrink-0 rounded border-control accent-brand">
                                            <span dir="ltr" class="text-body text-fg tabular">{{ $serial }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                                <p class="mt-1 text-small text-muted">{{ __('transfers.receive.serials_hint') }}</p>
                            @else
                                <label for="received-{{ $id }}" class="sr-only">{{ __('transfers.receive.received') }} {{ $i + 1 }}</label>
                                <input
                                    id="received-{{ $id }}"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    wire:model.live.debounce.400ms="received.{{ $id }}"
                                    @if ($row['error']) aria-invalid="true" @endif
                                    class="{{ $control }} {{ $row['error'] ? $bad : $ok }} text-end tabular md:max-w-32"
                                >
                            @endif
                            @if ($row['error'])
                                <p class="mt-1 text-small text-danger" role="alert">{{ $row['error'] }}</p>
                            @elseif ($row['short'])
                                <p class="mt-1"><span class="rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.rejected') }}">{{ $row['short'] }}</span></p>
                            @endif
                        </td>
                        <td data-label="{{ __('transfers.receive.reason') }}" @unless ($row['short']) data-card="hide" @endunless class="py-cell-y pe-edge">
                            @if ($row['short'])
                                <label for="reason-{{ $id }}" class="sr-only">{{ __('transfers.receive.reason') }}</label>
                                <div class="relative">
                                    <select id="reason-{{ $id }}" wire:model.live="reasons.{{ $id }}" @if ($row['reason_error']) aria-invalid="true" @endif class="{{ $control }} {{ $row['reason_error'] ? $bad : $ok }} appearance-none pe-10">
                                        <option value="">{{ __('transfers.receive.choose_reason') }}</option>
                                        @foreach (\App\Services\Stock\PostingService::SHORTAGE_REASONS as $reason)
                                            <option value="{{ $reason }}">{{ __('transfers.reasons.'.$reason) }}</option>
                                        @endforeach
                                    </select>
                                    <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                                </div>
                                @if ($row['reason_error'])
                                    <p class="mt-1 text-small text-danger" role="alert">{{ $row['reason_error'] }}</p>
                                @endif
                            @else
                                <span class="flex h-control items-center text-muted" aria-hidden="true">&mdash;</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    </fieldset>

    <div class="flex flex-wrap items-center justify-end gap-4 border-t border-default px-edge py-card-y">
        @error('receipt')
            <p class="me-auto text-body text-danger" role="alert">{{ $message }}</p>
        @else
            <p class="me-auto text-body text-muted">{{ __('transfers.receive.final_hint') }}</p>
        @enderror
        <x-submit-button target="confirm" action="confirm" icon="check_circle" :disabled="! $check['valid']" class="max-md:w-full">
            {{ __('transfers.receive.confirm') }}
        </x-submit-button>
    </div>
</section>
