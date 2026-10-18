{{--
    The app's only confirmation (CLAUDE.md "Confirmations"), for a Livewire
    component using App\Livewire\Concerns\ConfirmsActions; never the browser's confirm().
    <x-confirm-modal :confirm="$confirm" /> anywhere inside the component's view.

    Title names the action and the record; one sentence on the consequence;
    "Keep it" has the focus; the red button names the action and shows the
    loading state. Esc and a click outside close it; focus then goes back to
    the button that opened it (x-trap). A centred dialog; a bottom sheet on a phone.
--}}
@props(['confirm' => null])

@if ($confirm)
    <div
        wire:key="confirm-{{ $confirm['action'] }}-{{ $confirm['id'] }}"
        x-data
        x-trap.noscroll.inert="true"
        x-on:keydown.escape.window="$wire.dismissConfirm()"
        class="fixed inset-0 z-50 grid place-items-end overflow-y-auto text-start md:place-items-center md:p-4 print:hidden"
    >
        <div class="fixed inset-0 bg-scrim" x-on:click="$wire.dismissConfirm()" aria-hidden="true"></div>

        <div
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="confirm-title"
            aria-describedby="confirm-body"
            data-confirm-dialog
            class="relative w-full rounded-t-xl border border-default bg-card p-card shadow-pop md:max-w-md md:rounded-xl md:p-5"
        >
            <div class="flex items-start gap-4">
                <span class="grid size-control shrink-0 place-items-center rounded-full bg-danger-soft text-danger">
                    <x-icon name="warning" />
                </span>
                <div class="min-w-0">
                    <h2 id="confirm-title" class="break-words text-h3 font-semibold text-fg">{{ $confirm['title'] }}</h2>
                    <p id="confirm-body" class="mt-1 break-words text-body font-normal text-muted">{{ $confirm['body'] }}</p>
                </div>
            </div>

            @if ($confirm['reason'] !== null)
                <div class="mt-4">
                    <label for="confirm-reason" class="mb-1 block text-body font-semibold text-fg">
                        {{ $confirm['reason'] }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="confirm-reason"
                        rows="3"
                        maxlength="{{ \App\Services\Stock\PostingService::MAX_REASON }}"
                        required
                        wire:model="confirmReason"
                        wire:loading.attr="disabled"
                        wire:target="runConfirmed"
                        placeholder="{{ $confirm['placeholder'] }}"
                        @error('confirmReason') aria-invalid="true" aria-describedby="confirm-reason-error" @enderror
                        @class([
                            'w-full rounded-lg border bg-card px-3 py-2 text-body font-normal text-fg placeholder:text-subtle focus:outline-none focus:ring-2',
                            'border-danger focus:ring-danger/20' => $errors->has('confirmReason'),
                            'border-control focus:border-brand focus:ring-brand/20' => ! $errors->has('confirmReason'),
                        ])
                    ></textarea>
                    @error('confirmReason')
                        <p id="confirm-reason-error" class="mt-1 text-body font-normal text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div class="mt-4 flex flex-col-reverse gap-3 md:flex-row md:flex-wrap md:justify-end">
                {{-- The safe choice has the focus. --}}
                <button
                    type="button"
                    autofocus
                    wire:click="dismissConfirm"
                    wire:loading.attr="disabled"
                    wire:target="runConfirmed"
                    class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-card disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ __('confirm.keep') }}
                </button>
                <x-submit-button target="runConfirmed" action="runConfirmed" variant="danger">
                    {{ $confirm['button'] }}
                </x-submit-button>
            </div>
        </div>
    </div>
@endif
