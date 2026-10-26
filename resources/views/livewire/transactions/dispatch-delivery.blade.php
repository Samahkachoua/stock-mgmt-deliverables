@php
    $control = 'h-control w-full rounded-lg border bg-card px-3 text-body text-fg focus:outline-none focus:ring-2';
    $ok = 'border-control focus:border-brand focus:ring-brand/20';
    $bad = 'border-danger focus:ring-danger/20';
    $fields = [
        'driverName' => ['label' => __('deliveries.dispatch.driver_name'), 'max' => $max['driver_name'], 'type' => 'text', 'ltr' => false],
        'driverPhone' => ['label' => __('deliveries.dispatch.driver_phone'), 'max' => $max['driver_phone'], 'type' => 'tel', 'ltr' => true],
        'truckNo' => ['label' => __('deliveries.dispatch.truck_no'), 'max' => $max['truck_no'], 'type' => 'text', 'ltr' => true],
    ];
@endphp

<div>
    <button
        type="button"
        wire:click="show"
        class="inline-flex h-control items-center gap-2 rounded-lg bg-brand px-4 text-body font-semibold text-on-brand shadow-card hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface"
    >
        <x-icon name="local_shipping" />
        {{ __('deliveries.dispatch.button') }}
    </button>

    {{-- Same frame as the confirmation modal: centred dialog, bottom sheet on a phone. --}}
    @if ($open)
        <div
            x-data
            x-trap.noscroll.inert="true"
            x-on:keydown.escape.window="$wire.close()"
            class="fixed inset-0 z-50 grid place-items-end overflow-y-auto text-start md:place-items-center md:p-4 print:hidden"
        >
            <div class="fixed inset-0 bg-scrim" x-on:click="$wire.close()" aria-hidden="true"></div>

            <form
                wire:submit="dispatchNote"
                role="dialog"
                aria-modal="true"
                aria-labelledby="dispatch-title"
                aria-describedby="dispatch-hint"
                class="relative w-full rounded-t-xl border border-default bg-card p-card shadow-pop md:max-w-md md:rounded-xl md:p-5"
            >
                <h2 id="dispatch-title" class="text-h3 font-semibold text-fg">{{ __('deliveries.dispatch.title', ['reference' => $reference]) }}</h2>
                <p id="dispatch-hint" class="mt-1 text-body text-muted">{{ __('deliveries.dispatch.hint') }}</p>

                <fieldset wire:loading.attr="disabled" wire:target="dispatchNote" class="mt-4 flex flex-col gap-3">
                    <legend class="sr-only">{{ __('deliveries.dispatch.hint') }}</legend>
                    @foreach ($fields as $name => $field)
                        <div>
                            <label for="dispatch-{{ $name }}" class="mb-1 block text-body font-semibold text-fg">{{ $field['label'] }}</label>
                            <input
                                id="dispatch-{{ $name }}"
                                type="{{ $field['type'] }}"
                                maxlength="{{ $field['max'] }}"
                                autocomplete="off"
                                @if ($field['ltr']) dir="ltr" @endif
                                wire:model="{{ $name }}"
                                @if ($loop->first) autofocus @endif
                                @error($name) aria-invalid="true" aria-describedby="dispatch-{{ $name }}-error" @enderror
                                class="{{ $control }} {{ $errors->has($name) ? $bad : $ok }} @if ($field['ltr']) text-start @endif"
                            >
                            @error($name)
                                <p id="dispatch-{{ $name }}-error" class="mt-1 text-small text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </fieldset>

                @error('dispatch')
                    <p class="mt-3 text-body text-danger" role="alert">{{ $message }}</p>
                @enderror

                <div class="mt-4 flex flex-col-reverse gap-3 md:flex-row md:flex-wrap md:justify-end">
                    <button
                        type="button"
                        wire:click="close"
                        wire:loading.attr="disabled"
                        wire:target="dispatchNote"
                        class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-card disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('deliveries.dispatch.keep') }}
                    </button>
                    <x-submit-button target="dispatchNote" icon="local_shipping">
                        {{ __('deliveries.dispatch.confirm') }}
                    </x-submit-button>
                </div>
            </form>
        </div>
    @endif
</div>
