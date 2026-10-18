@use('App\Support\Format')
@use('App\Imports\Imports')

<div class="flex flex-col gap-section">
    <x-page-header icon="upload" :title="__('imports.title')" :subtitle="__('imports.subtitle')" />

    {{-- Start a new import. --}}
    <ul class="grid gap-4 md:grid-cols-2">
        @foreach ($types as $type)
            <li>
                <a href="{{ route('imports.create', $type->slug()) }}" wire:navigate class="flex min-h-20 items-center gap-4 rounded-xl border border-default bg-card p-card shadow-card hover:bg-card-hover">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-surface">
                        <x-icon :name="$type->icon()" class="size-7 text-muted" />
                    </span>
                    <span class="flex flex-col">
                        <span class="text-h3 font-semibold text-fg">{{ $type->title() }}</span>
                        <span class="text-body text-muted">{{ __('imports.types.'.str_replace('-', '_', $type->slug()).'.description') }}</span>
                    </span>
                    <x-icon name="chevron_right" class="ms-auto shrink-0 text-muted rtl:-scale-x-100" />
                </a>
            </li>
        @endforeach
    </ul>

    <h2 class="text-h3 font-semibold text-fg">{{ __('imports.list.heading') }}</h2>

    <x-data-table
        :columns="[
            'when' => ['label' => __('imports.list.when')],
            'file' => ['label' => __('imports.list.file'), 'card' => 'title'],
            'type' => ['label' => __('imports.list.type')],
            'by' => ['label' => __('imports.list.by'), 'priority' => 2],
            'result' => ['label' => __('imports.list.result')],
        ]"
        :paginator="$jobs"
        :loading="! $rowsLoaded"
        :empty-title="__('imports.list.empty_title')"
        :empty-body="__('imports.list.empty_body')"
    >
        @foreach ($jobs as $job)
            @php
                [$tone, $result] = match ($job->status) {
                    'CONFIRMED' => ['completed', trans_choice('imports.list.imported', $job->imported_rows, ['count' => Format::qty($job->imported_rows)])
                        .($job->error_rows > 0 ? ' · '.trans_choice('imports.list.skipped', $job->error_rows, ['count' => Format::qty($job->error_rows)]) : '')],
                    'FAILED' => ['rejected', __('imports.list.failed')],
                    'VALIDATED' => ['pending', __('imports.list.not_imported')],
                    default => ['transit', __('imports.list.reading')],
                };
            @endphp
            <x-data-table.row wire:key="import-{{ $job->id }}" :href="route('imports.show', $job->id)" :label="__('imports.list.open', ['file' => $job->file_name])">
                <x-data-table.cell col="when" class="whitespace-nowrap text-fg">{{ Format::dateTime($job->created_at) }}</x-data-table.cell>
                <x-data-table.cell col="file"><bdi class="font-semibold text-fg">{{ $job->file_name }}</bdi></x-data-table.cell>
                <x-data-table.cell col="type" class="text-fg">{{ Imports::forCode($job->job_type)->title() }}</x-data-table.cell>
                <x-data-table.cell col="by" class="text-fg"><bdi>{{ $job->creator->full_name }}</bdi></x-data-table.cell>
                <x-data-table.cell col="result">
                    <span class="inline-flex rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.'.$tone) }}">{{ $result }}</span>
                </x-data-table.cell>
            </x-data-table.row>
        @endforeach
    </x-data-table>
</div>
