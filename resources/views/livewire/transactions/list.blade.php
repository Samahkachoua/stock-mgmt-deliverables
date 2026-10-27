@use('App\Support\Format')

@php
    // Tablet: Items and Created by go. Phone card: reference + status, then customer, date (and reason).
    $columns = [
        'reference' => ['label' => __('transactions.list.reference'), 'card' => 'title'],
        'date' => ['label' => __('transactions.list.date')],
        'customer' => ['label' => __('transactions.list.customer'), 'card' => 'lead'],
        ...($ui->requiresDestination() ? ['route' => ['label' => __('transactions.list.route')]] : []),
        // Users of several warehouses: where each document is (transfers show From → To instead).
        ...($warehouses !== null && ! $ui->requiresDestination() ? ['warehouse' => ['label' => __('transactions.list.warehouse'), 'priority' => 2]] : []),
        'items' => ['label' => __('transactions.list.items'), 'align' => 'end', 'priority' => 2, 'card' => 'hide'],
        ...($ui->hasListColumn('reason') ? ['reason' => ['label' => __('transactions.list.reason')]] : []),
        // The draft it was confirmed from (the Order of a Delivery note).
        ...($ui->hasListColumn('draft') ? ['draft' => ['label' => \App\Support\Drafts\DraftTypes::get($ui->code)->label('singular')]] : []),
        'status' => ['label' => __('transactions.list.status'), 'card' => 'badge'],
        'created_by' => ['label' => __('transactions.list.created_by'), 'priority' => 2, 'card' => 'hide'],
    ];
    $filtered = $search !== '' || $customer !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '' || $direction !== '';
@endphp

<div class="flex flex-col gap-section">
    <x-page-header :type="$ui" :title="$ui->label('title')" :subtitle="$ui->label('subtitle')">
        @php
            // The report this list links to, when the user may open it.
            $reportKey = $ui->dispatchReceive() ? 'transfer-shortages' : ($ui->deliverySteps() ? 'delivery-differences' : null);
            $showsReport = $reportKey !== null && Gate::allows(\App\Reports\Reports::get($reportKey)->permission());
        @endphp
        @if ($showsReport || ($ui->creatable() && Gate::allows($ui->createPermission())))
            <x-slot:actions>
                @if ($ui->dispatchReceive())
                    @if ($showsReport)
                        <a href="{{ route('reports.show', 'transfer-shortages') }}" wire:navigate class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                            <x-icon name="report" class="text-muted" />
                            {{ __('transfers.shortages.link') }}
                        </a>
                    @endif
                @endif
                @if ($ui->deliverySteps())
                    @if ($showsReport)
                        <a href="{{ route('reports.show', 'delivery-differences') }}" wire:navigate class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                            <x-icon name="report" class="text-muted" />
                            {{ __('reports.delivery_differences.title') }}
                        </a>
                    @endif
                @endif
                @if ($ui->creatable() && Gate::allows($ui->createPermission()))
                <a
                    href="{{ $ui->route('create') }}"
                    wire:navigate
                    class="flex h-control items-center gap-2 rounded-lg px-4 text-body font-semibold text-on-brand shadow-card hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface {{ $ui->colours()['strong'] }}"
                >
                    <x-icon name="add" />
                    {{ $ui->label('new') }}
                </a>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-filters-bar :warehouses="$warehouses" :customers="$customers" :statuses="$statuses" :directions="$directions" :all-statuses="$pendingFirst ? __('transactions.filters.pending_first') : null" dates :date-error="$dateError" :search-placeholder="__('transactions.filters.search_placeholder')" />

    <x-data-table
        :columns="$columns"
        :paginator="$rows"
        :loading="! $rowsLoaded"
        :empty-title="$dateError ? __('feedback.date_order') : ($filtered ? __('transactions.list.no_matches_title') : $ui->label('empty_title'))"
        :empty-body="$dateError ? __('feedback.date_order_hint') : ($filtered ? __('transactions.list.no_matches_body') : $ui->label('empty_body'))"
    >
        {{-- Every empty state offers the next step. --}}
        <x-slot:empty-action>
            @if ($filtered)
                <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
            @elseif ($ui->creatable() && Gate::allows($ui->createPermission()))
                <x-empty-action :href="$ui->route('create')" icon="add" :label="$ui->label('new')" primary />
            @endif
        </x-slot:empty-action>

        @foreach ($rows as $row)
            <x-data-table.row wire:key="row-{{ $row['reference'] }}" :href="$ui->route('show', $row['reference'])" :label="__('transactions.list.open_document', ['reference' => $row['reference']])">
                <x-data-table.cell col="reference" class="whitespace-nowrap"><span dir="ltr" class="tabular font-semibold text-fg">{{ $row['reference'] }}</span></x-data-table.cell>
                <x-data-table.cell col="date" class="whitespace-nowrap text-fg">{{ Format::date($row['date']) }}</x-data-table.cell>
                <x-data-table.cell col="customer" class="font-semibold text-fg"><bdi>{{ $row['customer'] }}</bdi></x-data-table.cell>
                @if ($warehouses !== null && ! $ui->requiresDestination())
                    <x-data-table.cell col="warehouse" class="whitespace-nowrap text-fg"><bdi>{{ $row['from'] }}</bdi></x-data-table.cell>
                @endif
                @if ($ui->requiresDestination())
                    <x-data-table.cell col="route" class="whitespace-nowrap text-fg">
                        <bdi @class(['font-semibold' => ! ($row['incoming'] ?? false)])>{{ $row['from'] }}</bdi>
                        <x-icon name="arrow_forward" class="inline size-4 text-muted rtl:-scale-x-100" />
                        <bdi @class(['font-semibold' => $row['incoming'] ?? false])>{{ $row['to_warehouse'] }}</bdi>
                    </x-data-table.cell>
                @endif
                <x-data-table.cell col="items" align="end" class="whitespace-nowrap text-fg tabular">{{ trans_choice('transactions.summary.items', $row['items'], ['count' => Format::qty($row['items'])]) }}</x-data-table.cell>
                @if ($ui->hasListColumn('reason'))
                    <x-data-table.cell col="reason" class="text-fg">
                        @if ($row['reason'])
                            {{ __('transactions.reasons.'.$row['reason']) }}
                        @else
                            <span aria-hidden="true">&mdash;</span>
                        @endif
                    </x-data-table.cell>
                @endif
                @if ($ui->hasListColumn('draft'))
                    <x-data-table.cell col="draft" class="whitespace-nowrap text-fg">
                        @if ($row['draft'])
                            <span dir="ltr" class="tabular">{{ $row['draft'] }}</span>
                        @else
                            <span aria-hidden="true">&mdash;</span>
                        @endif
                    </x-data-table.cell>
                @endif
                <x-data-table.cell col="status">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <x-status-badge :code="$row['status']" :type="$ui" />
                        {{-- Delivered with a difference: see Delivery discrepancies. --}}
                        @if ($row['short'] ?? false)
                            <span class="inline-flex h-6 items-center rounded-md border px-2 text-small font-semibold {{ config('ui.statuses.rejected') }}">{{ __('deliveries.list.short') }}</span>
                        @endif
                    </span>
                </x-data-table.cell>
                <x-data-table.cell col="created_by" class="whitespace-nowrap">
                    <span class="inline-flex items-center gap-2 text-fg">
                        <bdi>{{ $row['created_by'] }}</bdi>
                    </span>
                </x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
