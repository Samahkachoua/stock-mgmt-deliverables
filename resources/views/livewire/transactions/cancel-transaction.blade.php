<div class="flex flex-col items-end gap-2">
    {{-- Asks in the confirmation modal, with a reason (CLAUDE.md "Confirmations"). --}}
    <button
        type="button"
        wire:click="ask"
        wire:loading.attr="disabled"
        wire:target="ask"
        class="inline-flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-danger shadow-card hover:bg-danger-soft disabled:cursor-not-allowed disabled:opacity-50"
    >
        <x-busy-icon target="ask" />
        {{ __('stock.cancel.button') }}
    </button>

    @if ($problem)
        <p class="max-w-md text-end text-body text-danger" role="alert">{{ $problem }}</p>
    @endif

    <x-confirm-modal :confirm="$confirm" />
</div>
