<x-settings.layout>
    <form wire:submit="save" class="rounded-xl border border-default bg-card p-card shadow-card" novalidate>
        <h2 class="text-h2 font-semibold text-fg">{{ __('settings.limits.heading') }}</h2>
        <p class="mt-1 text-body text-muted">{{ __('settings.limits.description') }}</p>

        @if ($this->types->isEmpty())
            <p class="mt-4 border-t border-default pt-4 text-body text-muted">{{ __('settings.limits.empty') }}</p>
        @else
            {{-- General limits: every customer, unless overridden below or on the item. --}}
            <h3 class="mt-4 border-t border-default pt-4 text-lead font-semibold text-fg">{{ __('settings.limits.global_heading') }}</h3>
            <div class="mt-4 grid gap-section md:grid-cols-2">
                @foreach ($this->types as $type)
                    <x-form.input
                        wire:key="limit-{{ $type->code }}"
                        :model="'limits.'.$type->code"
                        type="number"
                        min="0"
                        step="any"
                        inputmode="decimal"
                        class="tabular"
                        :label="__('settings.limits.label', ['type' => $type->label()])"
                        :suffix="__('settings.limits.unit')"
                        :hint="__('settings.limits.hint', ['type' => $type->label()])"
                    />
                @endforeach
            </div>

            {{-- Customer limits: used instead of the general limit for that customer. --}}
            <h3 class="mt-section border-t border-default pt-4 text-lead font-semibold text-fg">{{ __('settings.limits.customers_heading') }}</h3>
            <p class="mt-1 text-body text-muted">{{ __('settings.limits.customers_description') }}</p>

            @if ($customerLimits === [])
                <p class="mt-4 rounded-lg border border-dashed border-default px-4 py-4 text-body text-muted">{{ __('settings.limits.no_customers') }}</p>
            @else
                <div class="relative mt-4 overflow-x-auto rounded-lg border border-default">
                    <table data-form class="cards w-full text-table md:min-w-[560px]">
                        <thead>
                            <tr class="h-thead bg-surface text-small font-semibold uppercase tracking-wide text-muted">
                                <th scope="col" class="ps-edge text-start">{{ __('settings.limits.customer') }}</th>
                                @foreach ($this->types as $type)
                                    <th scope="col" class="px-cell text-start">{{ __('settings.limits.label', ['type' => $type->label()]) }}</th>
                                @endforeach
                                <th scope="col" class="w-14 pe-edge"><span class="sr-only">{{ __('settings.limits.remove_column') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-default">
                            @foreach ($customerLimits as $customerId => $limits)
                                @php($name = $this->customers[$customerId] ?? '')
                                <tr wire:key="customer-limit-{{ $customerId }}" class="align-top">
                                    <th scope="row" data-card="index" class="py-cell-y ps-edge text-start font-semibold text-fg"><span class="flex items-center md:h-control"><bdi>{{ $name }}</bdi></span></th>
                                    @foreach ($this->types as $type)
                                        @php($field = 'customerLimits.'.$customerId.'.'.$type->code)
                                        <td data-card="half" data-label="{{ __('settings.limits.label', ['type' => $type->label()]) }}" class="px-cell py-cell-y">
                                            <label for="field-{{ $customerId }}-{{ $type->code }}" class="sr-only">{{ __('settings.limits.customer_label', ['type' => $type->label(), 'customer' => $name]) }}</label>
                                            <input
                                                id="field-{{ $customerId }}-{{ $type->code }}"
                                                type="number"
                                                min="0"
                                                step="any"
                                                inputmode="decimal"
                                                wire:model.blur="{{ $field }}"
                                                placeholder="{{ __('settings.limits.use_general') }}"
                                                @error($field) aria-invalid="true" @enderror
                                                @class([
                                                    'h-control w-full rounded-lg border bg-card px-3 text-body text-fg tabular placeholder:text-subtle focus:outline-none focus:ring-2',
                                                    'border-danger focus:ring-danger/20' => $errors->has($field),
                                                    'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has($field),
                                                ])
                                            >
                                            @error($field)
                                                <p class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                                            @enderror
                                        </td>
                                    @endforeach
                                    <td data-card="corner" class="py-cell-y pe-edge text-end">
                                        <button
                                            type="button"
                                            wire:click="removeCustomer('{{ $customerId }}')"
                                            title="{{ __('settings.limits.remove', ['customer' => $name]) }}"
                                            class="grid size-control place-items-center rounded-lg text-muted hover:bg-danger-soft hover:text-danger"
                                        >
                                            <x-busy-icon name="delete" target="removeCustomer('{{ $customerId }}')" />
                                            <span class="sr-only">{{ __('settings.limits.remove', ['customer' => $name]) }}</span>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @php($addable = array_diff_key($this->customers, $customerLimits))
            @if ($addable !== [])
                <div class="mt-4 max-w-sm">
                    <label for="field-new-customer" class="mb-1 block text-body font-semibold text-fg">{{ __('settings.limits.add_customer') }}</label>
                    <div class="relative">
                        <select id="field-new-customer" wire:model.live="newCustomer" class="h-control w-full appearance-none rounded-lg border border-control bg-card pe-9 ps-3 text-body text-fg focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            <option value="">{{ __('settings.limits.choose_customer') }}</option>
                            @foreach ($addable as $id => $customerName)
                                <option value="{{ $id }}">{{ $customerName }}</option>
                            @endforeach
                        </select>
                        <x-busy-icon name="keyboard_arrow_down" target="newCustomer" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
                    </div>
                </div>
            @endif

            <p class="mt-4 text-body text-muted">{{ __('settings.limits.override_note') }}</p>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-4 border-t border-default pt-4">
                <x-submit-button target="save" icon="check" :disabled="$errors->any()" class="min-w-30">
                    {{ __('settings.save') }}
                </x-submit-button>
            </div>
        @endif
    </form>

    <x-confirm-modal :confirm="$confirm" />
</x-settings.layout>
