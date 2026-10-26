@use('App\Livewire\Drafts\DraftPage')

@php
    $isDraft = $draft['status'] === 'DRAFT';
@endphp

<div class="flex flex-col gap-section">
    <x-page-header :type="$kind" :title="$draft['reference']" :subtitle="$kind->label('singular')" :back="$kind->route()" :back-label="$kind->label('title')" ltr>
        <x-slot:badge>
            <x-draft-status-badge :code="$draft['status']" />
        </x-slot:badge>
        <x-slot:actions>
            {{-- Primary action first. --}}
            @if ($canConfirm)
                <x-submit-button target="confirmDraft" action="confirmDraft" icon="check" :variant="$kind->colours()['strong']" :busy="__('drafts.actions.confirming')">
                    {{ $kind->label('confirm') }}
                </x-submit-button>
            @endif
            @if ($canEdit)
                <a href="{{ $kind->route('edit', $draft['reference']) }}" wire:navigate class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                    <x-icon name="edit" class="text-muted" />
                    {{ __('drafts.actions.edit') }}
                </a>
            @endif
            <a href="{{ $kind->route('print', $draft['reference']) }}" target="_blank" rel="noopener" class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                <x-icon name="print" class="text-muted" />
                {{ __('transactions.details.print') }}
            </a>
            @if ($canCancel)
                <button type="button" wire:click="askCancel" class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-danger shadow-card hover:bg-danger-soft">
                    <x-busy-icon name="cancel" target="askCancel" />
                    {{ __('drafts.actions.cancel', ['type' => mb_strtolower($kind->label('singular'))]) }}
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($isDraft)
        {{-- Says what Confirm does, or why this user can't. --}}
        <p @class(['flex items-start gap-2 rounded-lg border px-4 py-3 text-body font-semibold', config('ui.statuses.draft')]) role="status">
            <x-icon name="info" class="mt-0.5 shrink-0" />
            <span>
                {{ __('drafts.details.not_in_stock_'.strtolower($kind->code), ['warehouse' => $draft['warehouse']]) }}
                @if ($confirmBlocked)
                    <span class="block font-normal">{{ $confirmBlocked }}</span>
                @elseif (! $canConfirm)
                    <span class="block font-normal">{{ __('drafts.details.waiting_for_confirmation') }}</span>
                @endif
            </span>
        </p>
    @endif

    <x-transaction-summary :rows="$summary" />

    @if ($draft['documents'] !== [])
        <section class="rounded-xl border border-default bg-card p-card shadow-card" aria-labelledby="created-documents">
            <h2 id="created-documents" class="text-h3 font-semibold text-fg">{{ $kind->label('created_heading') }}</h2>
            <ul class="mt-3 flex flex-col divide-y divide-default">
                @foreach ($draft['documents'] as $document)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <a href="{{ DraftPage::documentUrl($document['type'], $document['reference']) }}" wire:navigate class="inline-flex min-h-control items-center gap-2 font-semibold text-brand hover:underline">
                            <span dir="ltr" class="tabular">{{ $document['reference'] }}</span>
                        </a>
                        <span class="flex items-center gap-3 text-body text-fg">
                            <span class="inline-flex items-center gap-1.5"><x-icon name="warehouse" class="size-4 text-muted" /><bdi>{{ $document['warehouse'] }}</bdi></span>
                            <x-status-badge :code="$document['status']" :type="\App\Support\Transactions\TransactionTypes::get($document['type'])" />
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-draft-lines :lines="$draft['lines']" :line-errors="$lineErrors" />

    <x-activity-list :entries="$draft['activity']" />

    <x-confirm-modal :confirm="$confirm" />
</div>
