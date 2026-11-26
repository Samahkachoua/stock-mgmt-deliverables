@php
    // Phone card: the message on top with its state, then the date.
    $columns = [
        'message' => ['label' => __('notifications.list.message'), 'card' => 'title'],
        'when' => ['label' => __('notifications.list.when'), 'card' => 'lead'],
        'state' => ['label' => __('notifications.list.state'), 'card' => 'badge'],
    ];
    $filtered = $search !== '' || $group !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';
@endphp

<div class="flex flex-col gap-section">
    <x-page-header icon="notifications" :title="__('notifications.title')" :subtitle="__('notifications.subtitle', ['days' => $keepDays])">
        @if ($unread > 0)
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="markAllRead"
                    class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-link hover:bg-brand-soft"
                >
                    <x-icon name="check" />
                    {{ __('notifications.mark_all_read') }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-filters-bar
        :groups="$groups"
        :statuses="$statuses"
        :all-statuses="__('notifications.states.all')"
        dates
        :date-error="$dateError"
        :search-placeholder="__('notifications.list.search_placeholder')"
        :columns="$columns"
        list="notifications"
    />

    <x-data-table
        :columns="$columns"
        :paginator="$rows"
        :loading="! $rowsLoaded"
        :empty-title="$dateError ? __('feedback.date_order') : ($filtered ? __('transactions.list.no_matches_title') : __('notifications.empty_title'))"
        :empty-body="$dateError ? __('feedback.date_order_hint') : ($filtered ? __('transactions.list.no_matches_body') : __('notifications.empty_body'))"
    >
        <x-slot:empty-action>
            @if ($filtered)
                <x-empty-action action="resetFilters" icon="filter_alt_off" :label="__('transactions.filters.reset')" />
            @endif
        </x-slot:empty-action>

        @foreach ($rows as $item)
            @php
                [$tone, $state] = match (true) {
                    $item['resolved'] => ['cancelled', __('notifications.states.closed')],
                    $item['unread'] => ['transit', __('notifications.states.unread')],
                    default => ['draft', __('notifications.states.read')],
                };
            @endphp
            <x-data-table.row wire:key="notification-{{ $item['id'] }}" :href="$item['url']" :label="$item['message']">
                <x-data-table.cell col="message">
                    <span class="flex flex-col gap-0.5">
                        <span @class(['text-fg', 'font-semibold' => $item['unread']])>{{ $item['message'] }}</span>
                        @if ($item['reason'])
                            <span class="text-small text-muted">{{ __('notifications.reason') }}: {{ $item['reason'] }}</span>
                        @endif
                        @if ($item['outcome'])
                            <span class="text-small text-muted">{{ $item['outcome'] }}</span>
                        @endif
                    </span>
                </x-data-table.cell>
                <x-data-table.cell col="when" class="whitespace-nowrap text-fg">{{ $item['when'] }}</x-data-table.cell>
                <x-data-table.cell col="state">
                    <x-badge :tone="$tone">{{ $state }}</x-badge>
                </x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
