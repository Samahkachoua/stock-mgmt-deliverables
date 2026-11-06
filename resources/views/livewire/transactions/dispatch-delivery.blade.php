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
                    @if ($drivers->isEmpty())
                        <p class="rounded-lg border border-default bg-surface p-3 text-body text-muted" role="status">
                            {{ __('deliveries.dispatch.no_drivers') }}
                            @if ($canAddDriver)
                                <a href="{{ route('settings.drivers') }}" wire:navigate class="font-semibold text-link hover:underline">{{ __('deliveries.dispatch.add_driver') }}</a>
                            @endif
                        </p>
                    @else
                        <x-form.select
                            model="driverId"
                            :label="__('deliveries.dispatch.driver')"
                            :options="['' => __('deliveries.dispatch.choose_driver')] + $drivers->mapWithKeys(fn ($driver) => [$driver->id => $driver->label()])->all()"
                            required
                            autofocus
                        />

                        @if ($driver)
                            {{-- What the note keeps: read-only, change it in Settings › Drivers. --}}
                            <dl class="grid grid-cols-2 gap-3 rounded-lg border border-default bg-surface p-3" aria-live="polite">
                                @foreach ([
                                    'phone' => $driver->phone,
                                    'truck_no' => $driver->truck_no,
                                    'licence_no' => $driver->licence_no,
                                    'national_id' => $driver->national_id,
                                ] as $field => $value)
                                    <div>
                                        <dt class="text-small text-muted">{{ __('settings.drivers.'.$field) }}</dt>
                                        <dd dir="ltr" class="tabular text-start text-body font-semibold text-fg">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    @endif
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
                    <x-submit-button target="dispatchNote" icon="local_shipping" :disabled="$driver === null">
                        {{ __('deliveries.dispatch.confirm') }}
                    </x-submit-button>
                </div>
            </form>
        </div>
    @endif
</div>
