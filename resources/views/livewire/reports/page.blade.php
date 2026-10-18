@php
    // Translated once; the keys, tablet priorities and phone card roles come from the report.
    $columns = collect($definition->columns())->map(fn (array $column) => ['label' => __($column['label'])] + $column)->all();
@endphp

<div class="flex flex-col gap-section">
    <x-page-header :icon="$definition->icon()" :title="$definition->title()" :subtitle="$definition->description()" :back="route('reports')" :back-label="__('reports.title')">
        <x-slot:actions>
            <x-report.export-buttons :exportable="$exportable" />
        </x-slot:actions>
    </x-page-header>

    <x-report.filters :definitions="$definition->filters()" :choices="$choices" :values="$filters" :defaults="$defaults" :date-error="$dateError" />

    @if ($missing !== null)
        <div class="flex flex-col items-center rounded-xl border border-dashed border-default bg-card px-edge py-10 text-center">
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
</div>
