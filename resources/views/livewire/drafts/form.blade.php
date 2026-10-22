@use('App\Support\Format')

@php
    $receives = $kind->receives();
    $locked = $customerId === '';
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface disabled:text-subtle';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
    $header = $check['header'];
@endphp

<x-leave-guard class="flex flex-col gap-section">
    <x-page-header
        :type="$kind"
        :title="$title"
        :subtitle="$kind->label('form_subtitle')"
        :back="$reference === '' ? $kind->route() : $kind->route('show', $reference)"
        :back-label="$reference === '' ? $kind->label('title') : $reference"
    />

    <form wire:submit="save" class="flex flex-col gap-section" novalidate>
        {{-- Read-only from the moment Save is clicked until the answer is back. --}}
        <fieldset wire:loading.attr="disabled" wire:target="save, saveAndConfirm" class="flex min-w-0 flex-col gap-section">
        <legend class="sr-only">{{ $title }}</legend>

        {{-- Header card: Customer first; the rest waits for it. 3 per row desktop, 2 tablet, 1 phone. --}}
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
                            @if (! $customerUser) autofocus @endif
                            @disabled($customerUser)
                            class="{{ $control }} {{ isset($header['customer']) ? $bad : $ok }} appearance-none pe-10"
                        >
                            <option value="" disabled @selected($customerId === '')>{{ __('transactions.form.choose_customer_option') }}</option>
                            @foreach ($customers as $id => $name)
                                <option value="{{ $id }}" @selected($customerId === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
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
                    @elseif (isset($header['customer']))
                        <p class="mt-1 text-small text-danger" role="alert">{{ $header['customer'] }}</p>
                    @elseif ($locked)
                        <p class="mt-1 text-small text-muted">{{ __('transactions.form.customer_hint') }}</p>
                    @endif
                </div>

                <div>
                    <label for="field-branch" class="mb-1 block text-body font-semibold text-fg">
                        {{ $kind->label('branch') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="relative">
                        <select
                            id="field-branch"
                            wire:model.live="branchId"
                            @disabled($locked)
                            @isset($header['branch']) aria-invalid="true" aria-describedby="field-branch-error" @endisset
                            class="{{ $control }} {{ isset($header['branch']) ? $bad : $ok }} appearance-none pe-10"
                        >
                            <option value="">{{ __('drafts.form.choose_branch') }}</option>
                            @foreach ($branches as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                    </div>
                    @isset($header['branch'])
                        <p id="field-branch-error" class="mt-1 text-small text-danger" role="alert">{{ $header['branch'] }}</p>
                    @else
                        @if (! $locked && $branches === [])
                            {{-- Branches are added in Settings › Branches. --}}
                            <p class="mt-1 text-small text-muted">{{ __('drafts.form.no_branches') }}</p>
                        @else
                            <p class="mt-1 text-small text-muted">{{ $kind->label('branch_hint') }}</p>
                        @endif
                    @endisset
                </div>

                <div>
                    <label for="field-date" class="mb-1 block text-body font-semibold text-fg">
                        {{ __('transactions.form.date') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="field-date"
                        type="date"
                        wire:model.live="date"
                        max="{{ \App\Support\CompanyTime::today()->toDateString() }}"
                        @disabled($locked)
                        @isset($header['date']) aria-invalid="true" aria-describedby="field-date-error" @endisset
                        class="{{ $control }} {{ isset($header['date']) ? $bad : $ok }}"
                    >
                    @isset($header['date'])
                        <p id="field-date-error" class="mt-1 text-small text-danger" role="alert">{{ $header['date'] }}</p>
                    @endisset
                </div>

                <div class="md:col-span-2 xl:col-span-3">
                    <label for="field-reference" class="mb-1 block text-body font-semibold text-fg">
                        {{ __('transactions.form.reference') }}
                        <span class="font-normal text-muted">{{ __('transactions.form.optional') }}</span>
                    </label>
                    <input
                        id="field-reference"
                        type="text"
                        wire:model.blur="note"
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

        {{-- Lines: each in its own warehouse. --}}
        <section class="rounded-xl border border-default bg-card shadow-card" aria-labelledby="lines-heading">
            <div class="flex flex-wrap items-center justify-between gap-3 px-edge py-card-y">
                <div class="flex items-center gap-3">
                    <h2 id="lines-heading" class="text-h3 font-semibold text-fg">{{ __('drafts.form.lines_heading') }}</h2>
                    <span class="inline-flex h-6 items-center rounded-md bg-card-hover px-2 text-small font-semibold text-fg">
                        {{ trans_choice('transactions.form.filled_lines', $check['filled'], ['count' => Format::qty($check['filled'])]) }}
                    </span>
                </div>
                @if ($locked)
                    <p class="text-body text-muted">{{ __('transactions.form.lines_locked') }}</p>
                @endif
            </div>

            <fieldset data-lines @disabled($locked) class="min-w-0">
                <legend class="sr-only">{{ __('drafts.form.lines_heading') }}</legend>
                <div class="relative overflow-x-auto">
                    <table data-form class="cards w-full text-table md:min-w-[720px] xl:min-w-[960px]">
                        <thead>
                            <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                                <th scope="col" class="w-12 ps-edge text-start">#</th>
                                <th scope="col" class="w-44 px-cell text-start">{{ __('drafts.form.warehouse') }}</th>
                                <th scope="col" class="min-w-56 px-cell text-start xl:min-w-72">{{ __('transactions.lines.item') }}</th>
                                <th scope="col" class="w-36 px-cell text-start">{{ __('transactions.lines.unit') }}</th>
                                <th scope="col" @class(['px-cell text-start', 'w-44' => $receives, 'w-60' => ! $receives])>{{ __('transactions.lines.expiry') }}</th>
                                <th scope="col" class="w-48 px-cell text-start xl:w-64">{{ __('transactions.lines.quantity') }}</th>
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

                                    {{-- Warehouse: the previous row's pre-selected --}}
                                    <td data-label="{{ __('drafts.form.warehouse') }}" class="px-cell py-cell-y">
                                        <label for="{{ $id }}-warehouse" class="sr-only">{{ __('drafts.form.row_warehouse', ['row' => $loop->iteration]) }}</label>
                                        <div class="relative">
                                            <select
                                                id="{{ $id }}-warehouse"
                                                wire:model.live="lines.{{ $key }}.warehouse"
                                                @isset($errors['warehouse']) aria-invalid="true" @endisset
                                                class="{{ $control }} {{ isset($errors['warehouse']) ? $bad : $ok }} appearance-none pe-9"
                                            >
                                                <option value="">{{ __('drafts.form.choose_warehouse') }}</option>
                                                @foreach ($warehouses as $warehouseId => $name)
                                                    <option value="{{ $warehouseId }}">{{ $name }}</option>
                                                @endforeach
                                            </select>
                                            <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                        </div>
                                        @isset($errors['warehouse'])
                                            <p class="mt-1 text-small text-danger" role="alert">{{ $errors['warehouse'] }}</p>
                                        @endisset
                                    </td>

                                    {{-- Item --}}
                                    <td data-label="{{ __('transactions.lines.item') }}" class="px-cell py-cell-y">
                                        <label for="{{ $id }}-item" class="sr-only">{{ __('transactions.form.row_item', ['row' => $loop->iteration]) }}</label>
                                        <livewire:item-picker
                                            wire:model.live="lines.{{ $key }}.item"
                                            :customer="$customerId"
                                            :warehouse="$line['warehouse']"
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
                                            <span id="{{ $id }}-unit" tabindex="-1" class="flex h-control items-center px-3 text-body text-fg">{{ Format::unit($item['base']) }}</span>
                                        @else
                                            <label for="{{ $id }}-unit" class="sr-only">{{ __('transactions.form.row_unit', ['row' => $loop->iteration]) }}</label>
                                            <div class="relative">
                                                <select id="{{ $id }}-unit" wire:model.live="lines.{{ $key }}.unit" @disabled(! $item) class="{{ $control }} {{ isset($errors['unit']) ? $bad : $ok }} appearance-none pe-9">
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
                                    <td data-card="{{ $item && $item['expiry'] ? 'half' : 'hide' }}" data-label="{{ __('transactions.lines.expiry') }}" class="px-cell py-cell-y">
                                        @if ($item && $item['expiry'])
                                            <label for="{{ $id }}-expiry" class="sr-only">{{ __('transactions.form.row_expiry', ['row' => $loop->iteration]) }}</label>
                                            @if (! $receives)
                                                {{-- Dates in stock in the row's warehouse, nearest first, with what each holds. --}}
                                                <div class="relative">
                                                    <select id="{{ $id }}-expiry" wire:model.live="lines.{{ $key }}.expiry" class="{{ $control }} {{ isset($errors['expiry']) ? $bad : $ok }} appearance-none pe-9">
                                                        <option value="">{{ __('transactions.form.choose_expiry') }}</option>
                                                        @foreach ($row['expiry_options'] ?? [] as $lot)
                                                            <option value="{{ $lot['expiry'] }}">{{ Format::date(\Illuminate\Support\Carbon::parse($lot['expiry'])) }} · {{ Format::qtyUnit($lot['qty'], $item['base']) }}</option>
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

                                    {{-- Quantity, or the serial numbers --}}
                                    <td data-card="{{ $item && $item['serial'] ? 'full' : 'half' }}" data-label="{{ __('transactions.lines.quantity') }}" class="px-cell py-cell-y">
                                        @if ($item && $item['serial'])
                                            @if ($receives)
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
                                                <p class="mt-1 text-small text-muted">{{ trans_choice('transactions.form.serial_count', (int) $line['qty'], ['count' => Format::qty((int) $line['qty'])]) }}</p>
                                            @endisset
                                        @else
                                            <div class="flex items-center gap-2">
                                                <label for="{{ $id }}-qty" class="sr-only">{{ __('transactions.form.row_qty', ['row' => $loop->iteration]) }}</label>
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
                                                @if ($row['conversion'] ?? null)
                                                    <span class="whitespace-nowrap rounded-md bg-card-hover px-2 py-0.5 text-small font-semibold text-fg">= {{ $row['conversion'] }}</span>
                                                @endif
                                            </div>
                                            @isset($errors['qty'])
                                                <p id="{{ $id }}-qty-error" class="mt-1 text-small text-danger" role="alert">{{ $errors['qty'] }}</p>
                                            @elseif ($row['available_text'] ?? null)
                                                <p class="mt-1 text-small text-muted">
                                                    {{ __($receives ? 'transactions.form.in_stock_now' : 'transactions.form.available') }}
                                                    <span class="font-semibold text-fg">{{ $row['available_text'] }}</span>
                                                </p>
                                            @endisset
                                        @endif
                                    </td>

                                    <td data-card="corner" class="py-cell-y pe-edge text-end">
                                        @unless ($loop->last && ($row['blank'] ?? false))
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

        {{-- A draft never changes stock until it is confirmed. --}}
        <p class="flex items-center gap-3 rounded-xl border px-card py-3 text-body {{ config('ui.statuses.draft') }}" role="status">
            <x-icon name="info" />
            <span class="font-semibold">{{ trans_choice('drafts.form.notice_'.strtolower($kind->code), max($check['warehouses'], 1), ['count' => Format::qty($check['warehouses'])]) }}</span>
        </p>
        </fieldset>

        {{-- Sticky footer: summary, Cancel, Save draft (and Save and confirm). Phone: the summary on one line, buttons full width below. --}}
        <div data-form-footer class="sticky bottom-0 z-20 -mx-page flex flex-col gap-2 border-t border-default bg-card px-page py-2.5 shadow-pop md:flex-row md:flex-wrap md:items-center md:justify-between md:gap-4">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-lead font-semibold text-fg">
                    <span @class(['size-2.5 shrink-0 rounded-full', $kind->colours()['strong']]) aria-hidden="true"></span>
                    <span class="truncate">{{ $check['summary'] }}</span>
                </p>
                @if (! $check['valid'] && $check['problems'] !== [])
                    <p class="truncate text-small text-muted" aria-live="polite">{{ $check['problems'][0] }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ $reference === '' ? $kind->route() : $kind->route('show', $reference) }}" wire:navigate class="flex h-control shrink-0 items-center rounded-lg px-4 text-body font-semibold text-fg hover:bg-card-hover">
                    {{ __('transactions.form.cancel') }}
                </a>
                @if ($canConfirm)
                    <x-submit-button target="saveAndConfirm" action="saveAndConfirm" :disabled="! $check['valid']" icon="check" :variant="$kind->colours()['strong']" :busy="__('drafts.actions.confirming')" class="min-w-36 max-md:flex-1">
                        {{ __('drafts.actions.save_and_confirm') }}
                    </x-submit-button>
                @endif
                <x-submit-button target="save" :disabled="! $check['valid']" icon="edit" :variant="$canConfirm ? 'outline' : $kind->colours()['strong']" class="min-w-36 max-md:flex-1">
                    {{ __('drafts.actions.save') }}
                </x-submit-button>
            </div>
        </div>
    </form>

    <x-confirm-modal :confirm="$confirm" />
</x-leave-guard>
