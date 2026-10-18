@use('App\Support\Format')

@php
    $status = $document['status'];
    $summary = \App\Support\Transactions\Documents::summary($ui, $document);

    $user = auth()->user();
    $inTransit = $ui->dispatchReceive() && $status === 'DISPATCHED';

    // Transfers: only the destination receives, only the source cancels, and only while In transit.
    // Other posted documents are read-only; the only action on them is Cancel.
    $canReceive = $inTransit && Gate::allows('stock.transfer.receive') && $user->canAccessWarehouse($document['to_warehouse_id']);
    $canCancelPosted = $ui->cancellable() && Gate::allows($ui->cancelPermission()) && ($ui->dispatchReceive()
        ? $inTransit && $user->canAccessWarehouse($document['warehouse_id'])
        : $status === 'POSTED');

    // Waiting for approval: approvers get Approve / Reject (which explains when it's their own document).
    $pending = $status === 'PENDING_APPROVAL';
    $canDecide = $pending && $ui->approvePermission() !== null && Gate::allows($ui->approvePermission());
@endphp

<x-layouts.app :title="$document['reference']">
    <div class="flex flex-col gap-section">
        <x-page-header :type="$ui" :title="$document['reference']" :back="$ui->route()" :back-label="$ui->label('title')" ltr>
            <x-slot:badge>
                <x-status-badge :code="$status" />
            </x-slot:badge>
            <x-slot:actions>
                @if ($canDecide)
                    <livewire:transactions.decide-approval :transaction-id="$document['id']" />
                @endif
                {{-- The print view (A4, always light) opens in a new tab and prints itself. --}}
                <a href="{{ $ui->route('print', $document['reference']) }}" target="_blank" rel="noopener" class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                    <x-icon name="print" class="text-muted" />
                    {{ __('transactions.details.print') }}
                </a>
                @if ($canCancelPosted)
                    <livewire:transactions.cancel-transaction :transaction-id="$document['id']" />
                @endif
            </x-slot:actions>
        </x-page-header>

        @if ($pending && ! $canDecide)
            <p class="flex items-center gap-2 rounded-lg border px-4 py-3 text-body font-semibold {{ config('ui.statuses.pending') }}" role="status">
                <x-icon name="pending" />
                {{ __('stock.approval.waiting') }}
            </p>
        @endif

        <x-transaction-summary :rows="$summary" />

        @if ($canReceive)
            <livewire:transactions.receive-transfer :transaction-id="$document['id']" />
        @else
            @if ($inTransit)
                <p class="flex items-center gap-2 rounded-lg border px-4 py-3 text-body font-semibold {{ config('ui.statuses.transit') }}" role="status">
                    <x-icon name="local_shipping" />
                    {{ __('transfers.receive.read_only', ['warehouse' => $document['to_warehouse']]) }}
                </p>
            @endif
            <x-lines-table :type="$ui" :lines="$document['lines']" />
        @endif

        <x-activity-list :entries="$document['activity']" />
    </div>
</x-layouts.app>
