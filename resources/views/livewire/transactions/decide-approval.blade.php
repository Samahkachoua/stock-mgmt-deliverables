<div class="flex flex-col items-end gap-2">
    @if (! $decision->allowed())
        {{-- e.g. the approver's own document: say who must decide instead of showing buttons that can't work. --}}
        <p class="max-w-md text-end text-body text-muted" role="status">{{ $decision->message() === __('stock.errors.own_document') ? __('stock.approval.own') : $decision->message() }}</p>
    @else
        <div class="flex flex-wrap justify-end gap-3">
            {{-- Reject asks in the confirmation modal, with a reason; Approve never asks (CLAUDE.md "Confirmations"). --}}
            <button type="button" wire:click="startReject" wire:loading.attr="disabled" wire:target="approve, startReject" class="inline-flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-danger shadow-card hover:bg-danger-soft disabled:cursor-not-allowed disabled:opacity-50">
                <x-busy-icon target="startReject" />
                {{ __('transactions.details.reject') }}
            </button>
            <x-submit-button target="approve" action="approve" icon="check_circle">
                {{ __('transactions.details.approve') }}
            </x-submit-button>
        </div>
    @endif

    @if ($problem)
        <p class="max-w-md text-end text-body text-danger" role="alert">{{ $problem }}</p>
    @endif

    <x-confirm-modal :confirm="$confirm" />
</div>
