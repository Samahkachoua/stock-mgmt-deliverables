@use('App\Support\Format')

@php
    $counted = $ui->hasLineColumn('counted');
    // Expiry date column: typed on receipts; picked from the stock here on counts and stock going out.
    $expiryPick = $counted || $ui->hasLineColumn('expiry_pick');
    $expiryColumn = $expiryPick || $ui->hasLineColumn('expiry');
    $locked = $customerId === '';
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface disabled:text-subtle';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
    $header = $check['header'];
@endphp

<div class="flex flex-col gap-section">
    <x-page-header
        :type="$ui"
        :title="$ui->label('new')"
        :subtitle="$ui->label('form_subtitle')"
        :back="$ui->route()"
        :back-label="$ui->label('title')"
    >
        @if ($currentWarehouse)
            <x-slot:actions>
                <span class="inline-flex h-control items-center gap-2 rounded-lg border border-default bg-card px-3 text-body text-muted shadow-card">
                    <x-icon name="warehouse" class="text-muted" />
                    {{ __('transactions.form.warehouse') }}
                    <span class="font-semibold text-fg">{{ $currentWarehouse->name }}</span>
                </span>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form wire:submit="save" class="flex flex-col gap-section" novalidate>
        {{-- Read-only from the moment Save is clicked until the answer is back. --}}
        <fieldset wire:loading.attr="disabled" wire:target="save, runConfirmed" class="flex min-w-0 flex-col gap-section">
        <legend class="sr-only">{{ $ui->label('new') }}</legend>
        {{-- Header card: Customer first; the rest waits for it. --}}
        {{-- 3 fields per row on desktop, 2 on tablet, 1 on phone. --}}
        <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-label="{{ __('transactions.form.header') }}">
            <div class="grid gap-x-4 gap-y-3 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label for="field-customer" class="mb-1 block text-body font-semibold text-fg">
                        {{ __('transactions.form.customer') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="relative">
                        <select
                            id="field-customer"
                            wire:key="customer-{{ $customerVersion }}"
                            wire:change="chooseCustomer($event.target.value)"
                            required
                            autofocus
                            class="{{ $control }} {{ $ok }} appearance-none pe-10"
                        >
                            <option value="" disabled @selected($customerId === '')>{{ __('transactions.form.choose_customer_option') }}</option>
                            {{-- PHP turns numeric array keys into integers: compare as strings. --}}
                            @foreach ($customers as $id => $name)
                                <option value="{{ $id }}" @selected($customerId === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                        {{-- Choosing the customer loads their items and unlocks the rest of the form. --}}
                        <x-busy-icon name="keyboard_arrow_down" target="chooseCustomer, confirmCustomerChange, keepCustomer" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                    </div>
                    @if ($pendingCustomer)
                        <div class="mt-2 rounded-lg border px-3 py-2 text-body {{ config('ui.statuses.pending') }}" role="alert">
                            <p class="font-semibold">{{ trans_choice('transactions.form.change_customer_warning', max($check['filled'], 1), ['customer' => $customers[$pendingCustomer] ?? '', 'count' => Format::qty($check['filled'])]) }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" wire:click="confirmCustomerChange" class="h-control rounded-lg bg-brand px-4 text-body font-semibold text-on-brand hover:bg-brand-hover">
                                    {{ __('transactions.form.change_customer_confirm') }}
                                </button>
                                <button type="button" wire:click="keepCustomer" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                                    {{ __('transactions.form.change_customer_keep', ['customer' => $customers[$customerId] ?? '']) }}
                                </button>
                            </div>
                        </div>
                    @elseif ($locked)
                        <p class="mt-1 text-small text-muted">{{ __('transactions.form.customer_hint') }}</p>
                    @endif
                </div>

                <div>
                    <label for="field-date" class="mb-1 block text-body font-semibold text-fg">
                        {{ __('transactions.form.date') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="field-date"
                        type="date"
                        wire:model.live="date"
                        max="{{ now()->toDateString() }}"
                        @disabled($locked)
                        @isset($header['date']) aria-invalid="true" aria-describedby="field-date-error" @endisset
                        class="{{ $control }} {{ isset($header['date']) ? $bad : $ok }}"
                    >
                    @isset($header['date'])
                        <p id="field-date-error" class="mt-1 text-small text-danger" role="alert">{{ $header['date'] }}</p>
                    @endisset
                </div>

                @if ($ui->hasHeaderField('to_warehouse'))
                    {{-- Transfers leave from the current warehouse; it can't be changed here. --}}
                    <div>
                        <span class="mb-1 block text-body font-semibold text-fg">{{ __('transfers.from_warehouse') }}</span>
                        <span class="flex h-control items-center justify-between gap-2 rounded-lg border border-default bg-surface px-3 text-body font-semibold text-fg">
                            <span class="flex items-center gap-2">
                                <x-icon name="warehouse" class="text-muted" />
                                <bdi>{{ $currentWarehouse?->name }}</bdi>
                            </span>
                            <x-icon name="lock" class="size-4 text-muted" />
                        </span>
                    </div>
                @endif

                @if ($ui->hasHeaderField('to_warehouse'))
                    <div>
                        <label for="field-to-warehouse" class="mb-1 block text-body font-semibold text-fg">
                            {{ __('transactions.form.to_warehouse') }} <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <div class="relative">
                            <select
                                id="field-to-warehouse"
                                wire:model.live="toWarehouse"
                                @disabled($locked)
                                @isset($header['to_warehouse']) aria-invalid="true" aria-describedby="field-to-warehouse-error" @endisset
                                class="{{ $control }} {{ isset($header['to_warehouse']) ? $bad : $ok }} appearance-none pe-10"
                            >
                                <option value="">{{ __('transactions.form.choose_warehouse') }}</option>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                            <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                        </div>
                        @isset($header['to_warehouse'])
                            <p id="field-to-warehouse-error" class="mt-1 text-small text-danger" role="alert">{{ $header['to_warehouse'] }}</p>
                        @endisset
                    </div>
                @endif

                @if ($ui->hasHeaderField('reason'))
                    <div>
                        <label for="field-reason" class="mb-1 block text-body font-semibold text-fg">
                            {{ __('transactions.form.reason') }} <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <div class="relative">
                            <select
                                id="field-reason"
                                wire:model.live="reason"
                                @disabled($locked)
                                @isset($header['reason']) aria-invalid="true" aria-describedby="field-reason-error" @endisset
                                class="{{ $control }} {{ isset($header['reason']) ? $bad : $ok }} appearance-none pe-10"
                            >
                                <option value="">{{ __('transactions.form.choose_reason') }}</option>
                                @foreach ($ui->reasons() as $code)
                                    <option value="{{ $code }}">{{ __('transactions.reasons.'.$code) }}</option>
                                @endforeach
                            </select>
                            <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                        </div>
                        @isset($header['reason'])
                            <p id="field-reason-error" class="mt-1 text-small text-danger" role="alert">{{ $header['reason'] }}</p>
                        @endisset
                    </div>
                @endif

                <div @class(['md:col-span-2 xl:col-span-1' => count($ui->headerFields()) === 0])>
                    <label for="field-reference" class="mb-1 block text-body font-semibold text-fg">
                        {{ __('transactions.form.reference') }}
                        <span class="font-normal text-muted">{{ __('transactions.form.optional') }}</span>
                    </label>
                    <input
                        id="field-reference"
                        type="text"
                        wire:model.blur="reference"
                        maxlength="200"
                        placeholder="{{ __('transactions.form.reference_placeholder') }}"
                        @disabled($locked)
                        class="{{ $control }} {{ isset($header['reference']) ? $bad : $ok }}"
                    >
                    @isset($header['reference'])
                        <p class="mt-1 text-small text-danger" role="alert">{{ $header['reference'] }}</p>
                    @endisset
                </div>
            </div>
        </section>

        {{-- Lines --}}
        <section class="rounded-xl border border-default bg-card shadow-card" aria-labelledby="lines-heading">
            <div class="flex flex-wrap items-center justify-between gap-3 px-edge py-card-y">
                <div class="flex items-center gap-3">
                    <h2 id="lines-heading" class="text-h3 font-semibold text-fg">{{ $ui->label('items_heading') }}</h2>
                    <span class="inline-flex h-6 items-center rounded-md bg-card-hover px-2 text-small font-semibold text-fg">
                        {{ trans_choice('transactions.form.filled_lines', $check['filled'], ['count' => Format::qty($check['filled'])]) }}
                    </span>
                </div>
                @if ($locked)
                    <p class="text-body text-muted">{{ __('transactions.form.lines_locked') }}</p>
                @endif
            </div>

            <fieldset data-lines @disabled($locked) class="min-w-0">
                <legend class="sr-only">{{ $ui->label('items_heading') }}</legend>
                <div class="relative overflow-x-auto">
                    {{-- A table on desktop and tablet; on a phone every line is a card (the `cards` rules in app.css). --}}
                    <table data-form class="cards w-full text-table md:min-w-[672px] xl:min-w-[880px]">
                        <thead>
                            <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                                <th scope="col" class="w-12 ps-edge text-start">#</th>
                                <th scope="col" class="min-w-56 px-cell text-start xl:min-w-72">{{ __('transactions.lines.item') }}</th>
                                <th scope="col" class="w-36 px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                                @if ($expiryColumn)
                                    <th scope="col" @class(['px-cell text-start', 'w-60' => $expiryPick, 'w-44' => ! $expiryPick])>{{ __('transactions.lines.expiry') }}</th>
                                @endif
                                @if ($counted)
                                    <th scope="col" class="w-28 px-cell text-end">{{ __('transactions.lines.in_system') }}</th>
                                    <th scope="col" class="w-36 px-cell text-start">{{ __('transactions.lines.counted') }}</th>
                                    <th scope="col" class="w-28 px-cell text-center">{{ __('transactions.lines.difference') }}</th>
                                @else
                                    <th scope="col" class="w-48 px-cell text-start xl:w-64">{{ __('transactions.lines.quantity') }}</th>
                                @endif
                                <th scope="col" class="w-14 pe-edge"><span class="sr-only">{{ __('transactions.form.delete_row') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default">
                            @foreach ($lines as $key => $line)
                                @php
                                    $row = $check['rows'][$key];
                                    $errors = $row['shown'] ?? [];
                                    $item = $row['item'] ?? null;
                                    $id = 'line-'.$key;
                                @endphp
                                <tr wire:key="{{ $id }}" data-line class="align-top">
                                    <td data-card="index" class="py-cell-y ps-edge text-muted tabular"><span class="flex items-center md:h-control">{{ Format::qty($loop->iteration) }}</span></td>

                                    {{-- Item --}}
                                    <td data-label="{{ __('transactions.lines.item') }}" class="px-cell py-cell-y">
                                        <label for="{{ $id }}-item" class="sr-only">{{ __('transactions.form.row_item', ['row' => $loop->iteration]) }}</label>
                                        <livewire:item-picker
                                            wire:model.live="lines.{{ $key }}.item"
                                            :customer="$customerId"
                                            :invalid="isset($errors['item'])"
                                            :input-id="$id.'-item'"
                                            :next="$id.'-unit'"
                                            :key="'picker-'.$key"
                                        />
                                        @isset($errors['item'])
                                            <p class="mt-1 text-small text-danger" role="alert">{{ $errors['item'] }}</p>
                                        @endisset
                                    </td>

                                    {{-- Unit: base unit pre-selected --}}
                                    <td data-card="half" data-label="{{ __('transactions.lines.unit') }}" class="px-cell py-cell-y">
                                        @if ($item && $item['serial'])
                                            {{-- Serial-numbered items are counted one by one, in their base unit only. --}}
                                            <span id="{{ $id }}-unit" tabindex="-1" class="flex h-control items-center px-3 text-body text-fg">{{ Format::unit($item['base']) }}</span>
                                        @else
                                        <label for="{{ $id }}-unit" class="sr-only">{{ __('transactions.form.row_unit', ['row' => $loop->iteration]) }}</label>
                                        <div class="relative">
                                            <select
                                                id="{{ $id }}-unit"
                                                wire:model.live="lines.{{ $key }}.unit"
                                                @disabled(! $item)
                                                class="{{ $control }} {{ isset($errors['unit']) ? $bad : $ok }} appearance-none pe-9"
                                            >
                                                @if (! $item)
                                                    <option value="">&mdash;</option>
                                                @else
                                                    @foreach ($item['units'] as $unit => $factor)
                                                        <option value="{{ $unit }}">{{ Format::unit($unit) }}</option>
                                                    @endforeach
                                                @endif
                                            </select>
                                            <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                        </div>
                                        @endif
                                        @isset($errors['unit'])
                                            <p class="mt-1 text-small text-danger" role="alert">{{ $errors['unit'] }}</p>
                                        @endisset
                                    </td>

                                    {{-- Expiry date: only on rows whose item tracks expiry --}}
                                    @if ($expiryColumn)
                                        <td data-card="{{ $item && $item['expiry'] ? 'half' : 'hide' }}" data-label="{{ __('transactions.lines.expiry') }}" class="px-cell py-cell-y">
                                            @if ($item && $item['expiry'])
                                                <label for="{{ $id }}-expiry" class="sr-only">{{ __('transactions.form.row_expiry', ['row' => $loop->iteration]) }}</label>
                                                @if ($expiryPick)
                                                    {{-- Counting: any date here. Going out: those that may go out, nearest first, with what each holds. --}}
                                                    <div class="relative">
                                                        <select id="{{ $id }}-expiry" wire:model.live="lines.{{ $key }}.expiry" class="{{ $control }} {{ isset($errors['expiry']) ? $bad : $ok }} appearance-none pe-9">
                                                            <option value="">{{ __('transactions.form.choose_expiry') }}</option>
                                                            @foreach ($row['expiry_options'] ?? [] as $lot)
                                                                <option value="{{ $lot['expiry'] }}">{{ Format::date(\Illuminate\Support\Carbon::parse($lot['expiry'])) }} · {{ Format::qtyUnit($lot['qty'], $item['base']) }}{{ $lot['expired'] ? ' · '.__('transactions.form.expired') : '' }}</option>
                                                            @endforeach
                                                        </select>
                                                        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                                    </div>
                                                @else
                                                    <input id="{{ $id }}-expiry" type="date" wire:model.live="lines.{{ $key }}.expiry" class="{{ $control }} {{ isset($errors['expiry']) ? $bad : $ok }}">
                                                @endif
                                                @isset($errors['expiry'])
                                                    <p class="mt-1 text-small text-danger" role="alert">{{ $errors['expiry'] }}</p>
                                                @elseif ($row['expiry_past'] ?? false)
                                                    <p class="mt-1 flex items-start gap-1 text-small font-semibold text-status-pending" role="status">
                                                        <x-icon name="warning" class="mt-0.5 size-4" />
                                                        {{ __('transactions.form.expiry_past') }}
                                                    </p>
                                                @endisset
                                            @else
                                                <span class="flex h-control items-center text-muted" aria-hidden="true">&mdash;</span>
                                            @endif
                                        </td>
                                    @endif

                                    {{-- Quantity, or In system | Counted | Difference --}}
                                    @if ($counted)
                                        <td data-card="half" data-label="{{ __('transactions.lines.in_system') }}" class="px-cell py-cell-y text-end text-muted tabular">
                                            <span class="inline-flex items-center md:h-control">{{ isset($row['system']) ? Format::qty($row['system']) : '—' }}</span>
                                        </td>
                                    @endif
                                    <td data-card="{{ $item && $item['serial'] ? 'full' : 'half' }}" data-label="{{ __($counted ? 'transactions.lines.counted' : 'transactions.lines.quantity') }}" class="px-cell py-cell-y">
                                        @if ($item && $item['serial'])
                                            {{-- Serial-numbered: one serial per piece; the quantity follows. --}}
                                            @if ($ui->postingDirection() === 'IN')
                                                <label for="{{ $id }}-qty" class="sr-only">{{ __('transactions.form.row_serials', ['row' => $loop->iteration]) }}</label>
                                                <textarea
                                                    id="{{ $id }}-qty"
                                                    rows="2"
                                                    dir="ltr"
                                                    wire:model.live.debounce.500ms="lines.{{ $key }}.serials"
                                                    placeholder="{{ __('transactions.form.serials_placeholder') }}"
                                                    @isset($errors['serials']) aria-invalid="true" @endisset
                                                    class="{{ $control }} {{ isset($errors['serials']) ? $bad : $ok }} h-auto min-h-control py-2 tabular"
                                                ></textarea>
                                            @else
                                                <fieldset id="{{ $id }}-qty" class="max-h-40 overflow-y-auto rounded-lg border {{ isset($errors['serials']) ? 'border-danger' : 'border-control' }} bg-card px-3 py-1">
                                                    <legend class="sr-only">{{ __('transactions.form.row_serials', ['row' => $loop->iteration]) }}</legend>
                                                    @forelse ($row['serial_options'] ?? [] as $serial)
                                                        <label wire:key="{{ $id }}-serial-{{ $serial }}" class="flex min-h-control cursor-pointer items-center gap-3">
                                                            <input type="checkbox" wire:model.live="lines.{{ $key }}.picked" value="{{ $serial }}" class="size-5 shrink-0 rounded border-control accent-brand">
                                                            <span dir="ltr" class="text-body text-fg tabular">{{ $serial }}</span>
                                                        </label>
                                                    @empty
                                                        <p class="py-2 text-body text-muted">{{ __('transactions.form.no_serials_here') }}</p>
                                                    @endforelse
                                                </fieldset>
                                            @endif
                                            @isset($errors['serials'])
                                                <p class="mt-1 text-small text-danger" role="alert">{{ $errors['serials'] }}</p>
                                            @else
                                                <p class="mt-1 text-small text-muted">{{ $counted ? __('transactions.form.serials_found_hint') : trans_choice('transactions.form.serial_count', (int) $line['qty'], ['count' => Format::qty((int) $line['qty'])]) }}</p>
                                            @endisset
                                        @else
                                        <div class="flex items-center gap-2">
                                            <label for="{{ $id }}-qty" class="sr-only">{{ __($counted ? 'transactions.form.row_counted' : 'transactions.form.row_qty', ['row' => $loop->iteration]) }}</label>
                                            <input
                                                id="{{ $id }}-qty"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="off"
                                                wire:model.live.debounce.400ms="lines.{{ $key }}.qty"
                                                @disabled(! $item)
                                                @isset($errors['qty']) aria-invalid="true" aria-describedby="{{ $id }}-qty-error" @endisset
                                                class="{{ $control }} {{ isset($errors['qty']) ? $bad : $ok }} text-end tabular md:max-w-32"
                                            >
                                            @if (! $counted && ($row['conversion'] ?? null))
                                                <span class="whitespace-nowrap rounded-md bg-card-hover px-2 py-0.5 text-small font-semibold text-fg">= {{ $row['conversion'] }}</span>
                                            @endif
                                        </div>
                                        @isset($errors['qty'])
                                            <p id="{{ $id }}-qty-error" class="mt-1 text-small text-danger" role="alert">{{ $errors['qty'] }}</p>
                                        @elseif (! $counted && ($row['available_text'] ?? null))
                                            <p class="mt-1 text-small text-muted">
                                                {{ __($ui->limitedByStock() ? 'transactions.form.available' : 'transactions.form.in_stock_now') }}
                                                <span class="font-semibold text-fg">{{ $row['available_text'] }}</span>
                                            </p>
                                        @endisset
                                        @endif
                                    </td>
                                    @if ($counted)
                                        <td data-card="half" data-label="{{ __('transactions.lines.difference') }}" class="px-cell py-cell-y text-center"><span class="inline-flex items-center md:h-control">
                                            @php($difference = $row['difference'] ?? null)
                                            @if ($difference === null)
                                                <span class="text-muted" aria-hidden="true">&mdash;</span>
                                            @else
                                                <span @class([
                                                    'inline-flex min-w-12 justify-center rounded-md border px-2 py-0.5 text-small font-semibold',
                                                    config('ui.statuses.completed') => $difference > 0,
                                                    config('ui.statuses.rejected') => $difference < 0,
                                                    config('ui.statuses.cancelled') => $difference == 0,
                                                ])><span dir="ltr">{{ ($difference > 0 ? '+' : '').Format::qty($difference) }}</span></span>
                                            @endif
                                            </span>
                                        </td>
                                    @endif

                                    <td data-card="corner" class="py-cell-y pe-edge text-end">
                                        @unless ($loop->last && ($row['blank'] ?? false))
                                            {{-- Out of the Tab order so Tab goes Quantity → next row's Item. --}}
                                            <button
                                                type="button"
                                                tabindex="-1"
                                                wire:click="deleteLine('{{ $key }}')"
                                                title="{{ __('transactions.form.delete_row') }}"
                                                class="grid size-control place-items-center rounded-lg text-muted hover:bg-danger-soft hover:text-danger"
                                            >
                                                <x-busy-icon name="delete" target="deleteLine('{{ $key }}')" />
                                                <span class="sr-only">{{ __('transactions.form.delete_row_number', ['row' => $loop->iteration]) }}</span>
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </fieldset>
        </section>

        @php($destination = $ui->hasHeaderField('to_warehouse') ? $warehouses->firstWhere('id', $toWarehouse) : null)

        @if ($destination)
            <p class="flex items-center gap-3 rounded-xl border px-card py-3 text-body font-semibold {{ config('ui.statuses.transit') }}" role="status">
                <x-icon name="info" />
                {{ __('transfers.in_transit_notice', ['warehouse' => $destination->name]) }}
            </p>
        @endif

        @if ($check['approval'])
            <p class="flex items-center gap-3 rounded-xl border px-card py-3 text-body {{ config('ui.statuses.pending') }}" role="status">
                <x-icon name="info" />
                <span class="font-semibold">{{ __('transactions.form.approval_notice') }}</span>
            </p>
        @endif
        </fieldset>

        {{-- Sticky footer: summary, Cancel, Save (disabled until everything is valid; "Saving…" while it runs).
             Phone: the summary on one line, Save full width below it. --}}
        <div data-form-footer class="sticky bottom-0 z-20 -mx-page flex flex-col gap-2 border-t border-default bg-card px-page py-2.5 shadow-pop md:flex-row md:flex-wrap md:items-center md:justify-between md:gap-4">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-lead font-semibold text-fg">
                    <span @class(['size-2.5 shrink-0 rounded-full', $ui->colours()['strong']]) aria-hidden="true"></span>
                    <span class="truncate">{{ $check['summary'] }}</span>
                </p>
                @if (! $check['valid'] && $check['problems'] !== [])
                    <p class="truncate text-small text-muted" aria-live="polite">{{ $check['problems'][0] }}</p>
                @elseif (! $ui->cancellable() && ! $ui->dispatchReceive() && ! $check['approval'])
                    {{-- Damage / Adjustment saved under the limit: final. --}}
                    <p class="text-small text-muted max-md:hidden">{{ __('transactions.form.final_hint') }}</p>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ $ui->route() }}" wire:navigate class="flex h-control shrink-0 items-center rounded-lg px-4 text-body font-semibold text-fg hover:bg-card-hover">
                    {{ __('transactions.form.cancel') }}
                </a>
                <x-submit-button
                    target="save"
                    :disabled="! $check['valid']"
                    :icon="$check['approval'] ? 'verified_user' : 'check'"
                    :variant="$ui->colours()['strong']"
                    class="min-w-36 max-md:flex-1"
                >
                    {{ $check['approval'] ? __('transactions.form.send_for_approval') : ($destination ? __('transfers.send_to', ['warehouse' => $destination->name]) : $ui->label('save')) }}
                </x-submit-button>
            </div>
        </div>
    </form>

    {{-- Damage / Adjustment that post at once: final, so Save asks first. --}}
    <x-confirm-modal :confirm="$confirm" />
</div>
