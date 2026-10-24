@use('App\Support\Format')

@php
    // Tablet: Warehouses, Items and Created by go. Phone card: reference + status, then customer, branch, date, total.
    $columns = [
        'reference' => ['label' => __('transactions.list.reference'), 'card' => 'title'],
        'date' => ['label' => __('transactions.list.date')],
        'customer' => ['label' => __('transactions.list.customer'), 'card' => 'lead'],
        'branch' => ['label' => $kind->label('branch')],
        'warehouse' => ['label' => __('drafts.list.warehouse'), 'priority' => 2, 'card' => 'hide'],
        'items' => ['label' => __('transactions.list.items'), 'align' => 'end', 'priority' => 2, 'card' => 'hide'],
        'total' => ['label' => __('transactions.list.total'), 'align' => 'end'],
        'status' => ['label' => __('transactions.list.status'), 'card' => 'badge'],
        'created_by' => ['label' => __('transactions.list.created_by'), 'priority' => 2, 'card' => 'hide'],
    ];
    $filtered = $search !== '' || $customer !== '' || $branch !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';
    $customerUser = auth()->user()?->isCustomerUser();
@endphp

<div class="flex flex-col gap-section">
    <x-page-header :type="$kind" :title="$kind->label('title')" :subtitle="$kind->label('subtitle')">
        @can($kind->createPermission())
            <x-slot:actions>
                <a
                    href="{{ $kind->route('create') }}"
                    wire:navigate
                    class="flex h-control items-center gap-2 rounded-lg px-4 text-body font-semibold text-on-brand shadow-card hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-surface {{ $kind->colours()['strong'] }}"
                >
                    <x-icon name="add" />
                    {{ $kind->label('new') }}
                </a>
            </x-slot:actions>
        @endcan
    </x-page-header>

    {{-- A customer user sees only their own customer: no customer filter. --}}
    <x-filters-bar
        :customers="$customerUser ? null : $customers"
        :branches="$branches"
        :statuses="$statuses"
        :all-statuses="$draftsFirst ? __('drafts.filters.drafts_first') : null"
        dates
        :date-error="$dateError"
        :search-placeholder="__('drafts.filters.search_placeholder')"
    />

    <x-data-table
        :columns="$columns"
        :paginator="$rows"
        :loading="! $rowsLoaded"
        :empty-title="$dateError ? __('feedback.date_order') : ($filtered ? __('transactions.list.no_matches_title') : $kind->label('empty_title'))"
        :empty-body="$dateError ? __('feedback.date_order_hint') : ($filtered ? __('transactions.list.no_matches_body') : $kind->label('empty_body'))"
    >
        <x-slot:empty-action>
            @if ($filtered)
                <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
            @elseif (Gate::allows($kind->createPermission()))
                <x-empty-action :href="$kind->route('create')" icon="add" :label="$kind->label('new')" primary />
            @endif
        </x-slot:empty-action>

        @foreach ($rows as $row)
            <x-data-table.row wire:key="row-{{ $row['reference'] }}" :href="$kind->route('show', $row['reference'])" :label="__('transactions.list.open_document', ['reference' => $row['reference']])">
                <x-data-table.cell col="reference" class="whitespace-nowrap"><span dir="ltr" class="tabular font-semibold text-fg">{{ $row['reference'] }}</span></x-data-table.cell>
                <x-data-table.cell col="date" class="whitespace-nowrap text-fg">{{ Format::date($row['date']) }}</x-data-table.cell>
                <x-data-table.cell col="customer" class="font-semibold text-fg"><bdi>{{ $row['customer'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="branch" class="text-fg"><bdi>{{ $row['branch'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="warehouse" class="text-fg"><bdi>{{ $row['warehouse'] }}</bdi></x-data-table.cell>
                <x-data-table.cell col="items" align="end" class="whitespace-nowrap text-fg tabular">{{ trans_choice('transactions.summary.items', $row['items'], ['count' => Format::qty($row['items'])]) }}</x-data-table.cell>
                <x-data-table.cell col="total" align="end" class="whitespace-nowrap font-semibold text-fg tabular">
                    {{ trans_choice('transactions.summary.units', (int) $row['total'], ['count' => Format::qty($row['total'])]) }}
                </x-data-table.cell>
                <x-data-table.cell col="status"><x-draft-status-badge :code="$row['status']" /></x-data-table.cell>
                <x-data-table.cell col="created_by" class="whitespace-nowrap text-fg"><bdi>{{ $row['created_by'] }}</bdi></x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
