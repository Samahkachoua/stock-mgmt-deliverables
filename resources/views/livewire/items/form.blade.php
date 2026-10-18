@use('App\Support\Format')

@php
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface disabled:text-muted';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
    $baseName = $baseUnit?->label() ?? '';
    $lastKey = array_key_last($units);
@endphp

{{-- Cancel, the back link and other links ask first when something changed (WarnsBeforeLeaving). --}}
<x-leave-guard class="flex flex-col gap-section">
    <x-page-header
        icon="category"
        :title="$title"
        :subtitle="__('items.form_subtitle')"
        :back="route('items')"
        :back-label="__('items.title')"
    />

    @if ($locked)
        <p class="flex items-start gap-3 rounded-xl border px-4 py-4 text-body {{ config('ui.statuses.pending') }}" role="note">
            <x-icon name="lock_clock" class="mt-0.5" />
            <span>{{ __('items.locked_notice') }}</span>
        </p>
    @endif

    <form wire:submit="save" class="flex flex-col gap-section" novalidate>
        {{-- 1. Item --}}
        <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-labelledby="section-item">
            <h2 id="section-item" class="text-h3 font-semibold text-fg">{{ __('items.sections.item') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-form.select
                    model="customerId"
                    :label="__('items.fields.customer')"
                    :options="['' => __('transactions.form.choose_customer_option')] + $customers"
                    :hint="$locked ? __('items.locked_field') : null"
                    :disabled="$locked"
                    required
                />
                <x-form.input model="sku" :label="__('items.fields.code')" :hint="__('items.fields.code_hint')" ltr required class="uppercase tabular" />
                <x-form.input model="nameEn" :label="__('items.fields.name_en')" required />
                <x-form.input model="nameAr" :label="__('items.fields.name_ar')" dir="rtl" lang="ar" />
                <x-form.input model="nameFr" :label="__('items.fields.name_fr')" lang="fr" />
                <x-form.toggle class="self-end" model="active" :label="__('items.fields.active')" :hint="__('items.fields.active_hint')" />
            </div>
        </section>

        {{-- 2. Units --}}
        <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-labelledby="section-units">
            <h2 id="section-units" class="text-h3 font-semibold text-fg">{{ __('items.sections.units') }}</h2>

            <div class="mt-4 max-w-md">
                <x-form.select
                    model="baseUnitId"
                    :label="__('items.fields.base_unit')"
                    :options="$this->allUnits->mapWithKeys(fn ($unit) => [$unit->id => $unit->label()])->all()"
                    :hint="$locked ? __('items.locked_field') : __('items.fields.base_unit_hint')"
                    :disabled="$locked"
                    required
                />
            </div>

            <fieldset @disabled($trackSerial) class="mt-4 min-w-0">
                <legend class="text-body font-semibold text-fg">{{ __('items.fields.other_units') }}</legend>
                @if ($trackSerial)
                    <p class="mt-1 text-body text-muted">{{ __('items.units.serial_note') }}</p>
                @else
                    <p class="mt-1 text-body text-muted">{{ __('items.units.hint', ['base' => $baseName]) }}</p>
                @endif

                <ul class="mt-3 flex flex-col gap-3">
                    @foreach ($units as $key => $row)
                        @php($rowId = 'unit-'.$key)
                        <li wire:key="{{ $rowId }}" class="flex flex-wrap items-start gap-3">
                            <span class="flex h-control items-center text-body font-semibold text-fg tabular">{{ Format::qty(1) }}</span>
                            <div class="w-44">
                                <label for="{{ $rowId }}-unit" class="sr-only">{{ __('items.units.unit') }}</label>
                                <div class="relative">
                                    <select id="{{ $rowId }}-unit" wire:model.live="units.{{ $key }}.unit_id" class="{{ $control }} {{ $errors->has('units.'.$key.'.unit_id') ? $bad : $ok }} appearance-none pe-9">
                                        <option value="">{{ __('items.units.choose_unit') }}</option>
                                        @foreach ($this->allUnits as $unit)
                                            <option value="{{ $unit->id }}" @disabled($unit->id === $baseUnitId)>{{ $unit->label() }}</option>
                                        @endforeach
                                    </select>
                                    <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-2.5 top-1/2 -translate-y-1/2 text-muted" />
                                </div>
                                @error('units.'.$key.'.unit_id')
                                    <p class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                            <span class="flex h-control items-center text-body font-semibold text-muted" aria-hidden="true">=</span>
                            <div class="w-36">
                                <label for="{{ $rowId }}-factor" class="sr-only">{{ __('items.units.factor', ['base' => $baseName]) }}</label>
                                <input
                                    id="{{ $rowId }}-factor"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    wire:model.live.debounce.400ms="units.{{ $key }}.factor"
                                    class="{{ $control }} {{ $errors->has('units.'.$key.'.factor') ? $bad : $ok }} text-end tabular"
                                >
                                @error('units.'.$key.'.factor')
                                    <p class="mt-1 text-body text-danger" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                            <span class="flex h-control items-center text-body text-fg">{{ $baseName }}</span>
                            @unless ($key === $lastKey && $row['unit_id'] === '' && trim($row['factor']) === '')
                                <button
                                    type="button"
                                    tabindex="-1"
                                    wire:click="removeUnit('{{ $key }}')"
                                    title="{{ __('items.units.remove') }}"
                                    class="ms-auto grid size-control place-items-center rounded-lg text-muted hover:bg-danger-soft hover:text-danger"
                                >
                                    <x-busy-icon name="delete" target="removeUnit('{{ $key }}')" />
                                    <span class="sr-only">{{ __('items.units.remove') }}</span>
                                </button>
                            @endunless
                        </li>
                    @endforeach
                </ul>

                @if ($preview)
                    <p class="mt-4 rounded-lg bg-card-hover px-4 py-3 text-body text-fg" aria-live="polite">{{ $preview }}</p>
                @endif
            </fieldset>
        </section>

        {{-- 3. Tracking --}}
        <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-labelledby="section-tracking">
            <h2 id="section-tracking" class="text-h3 font-semibold text-fg">{{ __('items.sections.tracking') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="flex flex-col gap-3">
                    <x-form.toggle model="trackExpiry" :label="__('items.fields.track_expiry')" :hint="$locked ? __('items.locked_field') : __('items.fields.track_expiry_hint')" :disabled="$locked" />
                    <x-form.toggle model="trackSerial" :label="__('items.fields.track_serial')" :hint="$locked ? __('items.locked_field') : __('items.fields.track_serial_hint')" :disabled="$locked" />
                </div>
                <x-form.input
                    model="threshold"
                    inputmode="decimal"
                    :label="__('items.fields.threshold')"
                    :hint="__('items.fields.threshold_hint')"
                    :suffix="$baseName"
                    class="tabular"
                />
            </div>
        </section>

        <div class="sticky bottom-0 z-20 -mx-page flex flex-wrap items-center justify-end gap-3 border-t border-default bg-card px-page py-2.5 shadow-pop">
            @if ($productId)
                {{-- A wrongly created item can go while nothing uses it; otherwise it says where it is used. --}}
                <div class="me-auto flex min-w-0 flex-wrap items-center gap-3">
                    <button
                        type="button"
                        wire:click="askDelete"
                        wire:loading.attr="disabled"
                        wire:target="askDelete, save, runConfirmed"
                        class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-danger hover:bg-card-hover disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <x-busy-icon name="delete" target="askDelete" class="size-5" />
                        {{ __('items.delete') }}
                    </button>
                    @if ($deleteBlocked)
                        <p data-delete-blocked class="flex min-w-0 items-center gap-1.5 text-body font-semibold text-danger" role="alert">
                            <x-icon name="report" class="size-4 shrink-0" />
                            <span>{{ $deleteBlocked }}</span>
                        </p>
                    @endif
                </div>
            @endif
            <a href="{{ route('items') }}" wire:navigate class="flex h-control items-center rounded-lg px-4 text-body font-semibold text-fg hover:bg-card-hover">
                {{ __('settings.cancel') }}
            </a>
            <x-submit-button target="save" icon="check" :disabled="$errors->any()" class="min-w-36">
                {{ __('items.save') }}
            </x-submit-button>
        </div>
    </form>

    <x-confirm-modal :confirm="$confirm" />
</x-leave-guard>
