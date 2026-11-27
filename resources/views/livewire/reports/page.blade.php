@php
    // Translated once; the keys, tablet priorities and phone card roles come from the report.
    $columns = collect($definition->columns())->map(fn (array $column) => ['label' => __($column['label'])] + $column)->all();
@endphp

<div class="flex flex-col gap-section">
    <x-page-header :icon="$definition->icon()" :title="$definition->title()" :subtitle="$definition->description()" :back="route('reports')" :back-label="__('reports.title')" />

    <x-report.filters :definitions="$definition->filters()" :choices="$choices" :values="$filters" :defaults="$defaults" :date-error="$dateError" :columns="$columns" :list="'report-'.$definition->key()" :exportable="$exportable" />

    @if ($missing !== null)
        <div class="flex flex-col items-center rounded-xl border border-dashed border-default bg-card px-edge py-empty text-center">
            <x-icon name="info" class="size-10 text-muted" />
            <p class="mt-3 text-lead font-semibold text-fg">{{ $missing }}</p>
        </div>
    @else
        @if ($summary !== [])
            <dl class="grid grid-cols-2 gap-3 md:flex md:flex-wrap">
                @foreach ($summary as $item)
                    <div class="min-w-0 rounded-xl border border-default bg-card px-4 py-3 shadow-card md:min-w-44 md:px-4">
                        <dt class="text-small font-semibold uppercase tracking-wide text-muted">{{ $item['label'] }}</dt>
                        <dd class="mt-1 text-lead font-semibold text-fg tabular">{{ $item['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($definition->rowsView() !== null)
            {{-- The report shows its rows its own way (Report::rowsView()): same loading, empty state and pages. --}}
            <x-table-loading :busy="! $rowsLoaded" class="flex flex-col gap-section">
                @if (! $rowsLoaded)
                    <div wire:init="loadRows" class="flex flex-col gap-section" aria-hidden="true">
                        @for ($card = 0; $card < 3; $card++)
                            <div class="flex flex-col gap-3 rounded-xl border border-default bg-card p-card shadow-card">
                                <span class="block h-4 w-48 max-w-full animate-pulse rounded bg-card-hover"></span>
                                <span class="block h-3 w-32 max-w-full animate-pulse rounded bg-card-hover"></span>
                                <span class="block h-20 w-full animate-pulse rounded-lg bg-card-hover"></span>
                            </div>
                        @endfor
                    </div>
                @elseif ($rows->isEmpty())
                    <div class="flex flex-col items-center rounded-xl border border-default bg-card px-card py-empty text-center shadow-card">
                        <x-icon name="inventory_2" class="size-9 text-muted" />
                        <h2 class="mt-3 text-lead font-semibold text-fg">{{ __('reports.page.empty_title') }}</h2>
                        <p class="mt-1 max-w-md text-body text-muted">{{ __('reports.page.empty_body') }}</p>
                        <button type="button" wire:click="resetFilters" class="mt-4 h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                            {{ __('transactions.filters.reset') }}
                        </button>
                    </div>
                @else
                    @include($definition->rowsView(), ['rows' => $rows])

                    <div class="overflow-hidden rounded-xl border border-default bg-card shadow-card [&>nav]:border-t-0">
                        <x-data-table.pagination :paginator="$rows" />
                    </div>
                @endif
            </x-table-loading>
        @else
        <x-data-table
            :columns="$columns"
            :paginator="$rows"
            :loading="! $rowsLoaded"
            :empty-title="__('reports.page.empty_title')"
            :empty-body="__('reports.page.empty_body')"
        >
            <x-slot:empty-action>
                <button type="button" wire:click="resetFilters" class="h-control rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                    {{ __('transactions.filters.reset') }}
                </button>
            </x-slot:empty-action>

            @foreach ($rows as $row)
                <x-report.row :row="$row" :columns="$columns" wire:key="report-row-{{ $rows->firstItem() + $loop->index }}" />
            @endforeach
        </x-data-table>
        @endif
    @endif
</div>
