@use('App\Support\Format')

@php
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg focus:outline-none focus:ring-2';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
@endphp

{{-- Confirming the delivery: Ordered | Delivered (editable) | reason when short, and what was delivered instead. --}}
<section class="overflow-hidden rounded-xl border border-default bg-card shadow-card" aria-labelledby="deliver-heading">
    <div class="flex flex-wrap items-start justify-between gap-3 px-edge py-card-y">
        <div>
            <h2 id="deliver-heading" class="text-h3 font-semibold text-fg">{{ __('deliveries.deliver.heading') }}</h2>
            <p class="text-body text-muted">{{ __('deliveries.deliver.hint') }}</p>
        </div>
    </div>

    {{-- Read-only from the moment "Confirm delivery" is clicked until the answer is back. --}}
    <fieldset wire:loading.attr="disabled" wire:target="confirm" class="min-w-0">
    <legend class="sr-only">{{ __('deliveries.deliver.heading') }}</legend>
    <div class="relative overflow-x-auto">
        <table data-form class="cards w-full text-table md:min-w-[672px] xl:min-w-[880px]">
            <thead>
                <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                    <th scope="col" class="w-14 ps-edge text-start">#</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.item') }}</th>
                    <th scope="col" class="px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                    <th scope="col" class="px-cell text-end">{{ __('deliveries.deliver.ordered') }}</th>
                    <th scope="col" class="w-44 px-cell text-start xl:w-56">{{ __('deliveries.deliver.delivered') }}</th>
                    <th scope="col" class="w-64 pe-edge text-start xl:w-80">{{ __('deliveries.deliver.reason') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-default">
                @foreach ($document['lines'] as $i => $line)
                    @php
                        $id = $line['id'];
                        $row = $check['rows'][$id];
                        $replacement = $row['replacement'];
                        $chosen = $replacement['item'] ?? null;
                    @endphp
                    <tr wire:key="deliver-{{ $id }}" class="align-top">
                        <td data-card="index" class="py-cell-y ps-edge text-muted tabular"><span class="flex items-center md:h-control">{{ Format::qty($i + 1) }}</span></td>
                        <td class="px-cell py-cell-y">
                            <bdi class="font-semibold text-fg">{{ $line['name'] }}</bdi>
                            <span dir="ltr" class="tabular ms-1 rounded bg-card-hover px-1.5 py-0.5 text-small font-semibold text-muted">{{ $line['code'] }}</span>
                            @if (filled($line['expiry']))
                                <p class="mt-0.5 text-small text-muted">{{ \App\Support\Batches::label($line['expiry'], $line['batch']) }}</p>
                            @endif
                        </td>
                        <td data-card="half" data-label="{{ __('transactions.lines.unit') }}" class="px-cell py-cell-y text-fg"><span class="inline-flex items-center md:h-control">{{ Format::unit($line['unit']) }}</span></td>
                        <td data-card="half" data-label="{{ __('deliveries.deliver.ordered') }}" class="px-cell py-cell-y text-end font-semibold text-fg tabular"><span class="inline-flex items-center md:h-control">{{ Format::qty($line['qty']) }}</span></td>
                        <td data-label="{{ __('deliveries.deliver.delivered') }}" class="px-cell py-cell-y">
                            @if ($line['serials'] !== [])
                                {{-- Serial items: untick what was not delivered. --}}
                                <fieldset class="max-h-40 overflow-y-auto rounded-lg border {{ $row['error'] ? 'border-danger' : 'border-control' }} bg-card px-3 py-1">
                                    <legend class="sr-only">{{ __('deliveries.deliver.serials_hint') }}</legend>
                                    @foreach ($line['serials'] as $serial)
                                        <label wire:key="deliver-{{ $id }}-{{ $serial }}" class="flex min-h-control cursor-pointer items-center gap-3">
                                            <input type="checkbox" wire:model.live="serials.{{ $id }}" value="{{ $serial }}" class="size-5 shrink-0 rounded border-control accent-brand">
                                            <span dir="ltr" class="text-body text-fg tabular">{{ $serial }}</span>
                                        </label>
                                    @endforeach
                                </fieldset>
                                <p class="mt-1 text-small text-muted">{{ __('deliveries.deliver.serials_hint') }}</p>
                            @else
                                <label for="delivered-{{ $id }}" class="sr-only">{{ __('deliveries.deliver.delivered') }} {{ $i + 1 }}</label>
                                <input
                                    id="delivered-{{ $id }}"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    wire:model.live.debounce.400ms="delivered.{{ $id }}"
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
                        <td data-label="{{ __('deliveries.deliver.reason') }}" @unless ($row['short']) data-card="hide" @endunless class="py-cell-y pe-edge">
                            @if ($row['short'])
                                <label for="reason-{{ $id }}" class="sr-only">{{ __('deliveries.deliver.reason') }}</label>
                                <div class="relative">
                                    <select id="reason-{{ $id }}" wire:model.live="reasons.{{ $id }}" @if ($row['reason_error']) aria-invalid="true" @endif class="{{ $control }} {{ $row['reason_error'] ? $bad : $ok }} appearance-none pe-10">
                                        <option value="">{{ __('deliveries.deliver.choose_reason') }}</option>
                                        @foreach (\App\Services\Stock\PostingService::DELIVERY_REASONS as $reason)
                                            <option value="{{ $reason }}">{{ __('deliveries.reasons.'.$reason) }}</option>
                                        @endforeach
                                    </select>
                                    <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                                </div>
                                @if ($row['reason_error'])
                                    <p class="mt-1 text-small text-danger" role="alert">{{ $row['reason_error'] }}</p>
                                @endif

                                {{-- Another item delivered instead: which one, how much. --}}
                                @if (($reasons[$id] ?? '') === 'REPLACED')
                                    <div class="mt-2 flex flex-col gap-2 rounded-lg border border-default bg-surface p-3">
                                        <label for="instead-{{ $id }}-item" class="text-small font-semibold text-fg">{{ __('deliveries.deliver.instead_item', ['item' => $line['name']]) }}</label>
                                        <livewire:item-picker
                                            wire:model.live="instead.{{ $id }}.item"
                                            :customer="$document['customer_id']"
                                            :warehouse="$document['warehouse_id']"
                                            :services="true"
                                            :invalid="$row['instead_error'] !== null && $chosen === null"
                                            :input-id="'instead-'.$id.'-item'"
                                            :next="'instead-'.$id.'-qty'"
                                            :key="'instead-picker-'.$id"
                                        />
                                        @if ($chosen)
                                            <div class="grid grid-cols-2 gap-2">
                                                @unless ($chosen['serial'])
                                                    <div class="relative">
                                                        <label for="instead-{{ $id }}-unit" class="sr-only">{{ __('transactions.lines.unit') }}</label>
                                                        <select id="instead-{{ $id }}-unit" wire:model.live="instead.{{ $id }}.unit" class="{{ $control }} {{ $ok }} appearance-none pe-9">
                                                            @foreach ($chosen['units'] as $unit => $factor)
                                                                <option value="{{ $unit }}">{{ Format::unit($unit) }}</option>
                                                            @endforeach
                                                        </select>
                                                        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                                    </div>
                                                    <div>
                                                        <label for="instead-{{ $id }}-qty" class="sr-only">{{ __('deliveries.deliver.instead_qty') }}</label>
                                                        <input id="instead-{{ $id }}-qty" type="text" inputmode="numeric" autocomplete="off" placeholder="{{ __('deliveries.deliver.instead_qty') }}" wire:model.live.debounce.400ms="instead.{{ $id }}.qty" class="{{ $control }} {{ $ok }} text-end tabular">
                                                    </div>
                                                @endunless
                                                @if ($chosen['expiry'])
                                                    <div class="relative col-span-2">
                                                        <label for="instead-{{ $id }}-expiry" class="sr-only">{{ __('deliveries.deliver.instead_expiry') }}</label>
                                                        <select id="instead-{{ $id }}-expiry" wire:model.live="instead.{{ $id }}.expiry" class="{{ $control }} {{ $ok }} appearance-none pe-9">
                                                            <option value="">{{ __('transactions.form.choose_lot') }}</option>
                                                            @foreach ($replacement['lots'] as $lot)
                                                                <option value="{{ $lot['lot'] }}">{{ \App\Support\Batches::label($lot['expiry'], $lot['batch']) }} · {{ Format::qtyUnit($lot['qty'], $chosen['base']) }}</option>
                                                            @endforeach
                                                        </select>
                                                        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                                    </div>
                                                @endif
                                                @if ($chosen['serial'])
                                                    <fieldset class="col-span-2 max-h-40 overflow-y-auto rounded-lg border border-control bg-card px-3 py-1">
                                                        <legend class="sr-only">{{ __('deliveries.deliver.instead_qty') }}</legend>
                                                        @foreach ($replacement['serials'] as $serial)
                                                            <label wire:key="instead-{{ $id }}-{{ $serial }}" class="flex min-h-control cursor-pointer items-center gap-3">
                                                                <input type="checkbox" wire:model.live="instead.{{ $id }}.picked" value="{{ $serial }}" class="size-5 shrink-0 rounded border-control accent-brand">
                                                                <span dir="ltr" class="text-body text-fg tabular">{{ $serial }}</span>
                                                            </label>
                                                        @endforeach
                                                    </fieldset>
                                                @endif
                                            </div>
                                            @if ($replacement['available'] !== null)
                                                <p class="text-small text-muted">{{ __('transactions.form.available', ['qty' => $replacement['available']]) }}</p>
                                            @endif
                                        @endif
                                        @if ($row['instead_error'])
                                            <p class="text-small text-danger" role="alert">{{ $row['instead_error'] }}</p>
                                        @endif
                                    </div>
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
        @error('delivery')
            <p class="me-auto text-body text-danger" role="alert">{{ $message }}</p>
        @else
            <p class="me-auto text-body text-muted">{{ __('deliveries.deliver.final_hint') }}</p>
        @enderror
        <x-submit-button target="confirm" action="confirm" icon="check_circle" :disabled="! $check['valid']" class="max-md:w-full">
            {{ __('deliveries.deliver.confirm') }}
        </x-submit-button>
    </div>
</section>
