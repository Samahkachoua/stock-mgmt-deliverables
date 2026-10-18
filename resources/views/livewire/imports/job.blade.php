@use('App\Support\Format')

@php
    $step = $job->status === 'CONFIRMED' ? 3 : 2;
    $importable = $counts['ready'] + $counts['warnings'];
    $keyColumns = array_slice($import->columns(), 0, 3);
    $result = $job->result ?? [];
@endphp

<div class="flex flex-col gap-section">
    <x-page-header icon="upload" :title="$import->title()" :back="route('imports')" :back-label="__('imports.title')">
        <x-slot:badge>
            <bdi class="rounded bg-card-hover px-2 py-0.5 text-body font-semibold text-muted">{{ $job->file_name }}</bdi>
        </x-slot:badge>
    </x-page-header>

    <x-import.steps :current="$step" />

    @if ($job->status === 'UPLOADED')
        {{-- Being read in the background: show how far it got. --}}
        <section wire:poll.1s class="rounded-xl border border-default bg-card p-card shadow-card" role="status" aria-live="polite">
            <h2 class="text-lead font-semibold text-fg">{{ __('imports.reading.heading') }}</h2>
            @php
                $percent = $job->total_rows > 0 ? (int) floor($job->processed_rows / $job->total_rows * 100) : 0;
            @endphp
            <p class="mt-1 text-body text-muted">
                {{ $job->total_rows > 0
                    ? __('imports.reading.progress', ['done' => Format::qty($job->processed_rows), 'total' => Format::qty($job->total_rows)])
                    : __('imports.reading.opening') }}
            </p>
            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-surface">
                <div class="h-full rounded-full bg-brand transition-all" style="width: {{ $percent }}%"></div>
            </div>
        </section>
    @elseif ($job->status === 'FAILED')
        <section class="flex flex-col items-start gap-4 rounded-xl border p-card {{ config('ui.statuses.rejected') }}" role="alert">
            <p class="flex items-center gap-2 text-lead font-semibold"><x-icon name="warning" />{{ $job->error_message }}</p>
            <a href="{{ route('imports.create', $import->slug()) }}" wire:navigate class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg hover:bg-card-hover">
                <x-icon name="upload" />{{ __('imports.check.upload_another') }}
            </a>
        </section>
    @else
        @if ($job->status === 'CONFIRMED')
            <section class="flex flex-col gap-2 rounded-xl border p-card {{ config('ui.statuses.completed') }}" role="status">
                <p class="flex items-center gap-2 text-lead font-semibold">
                    <x-icon name="check_circle" />
                    @isset($result['items'])
                        {{ trans_choice('imports.done.items', $result['items'], ['count' => Format::qty($result['items'])]) }}
                    @else
                        {{ trans_choice('imports.done.documents', count($result['documents'] ?? []), ['count' => Format::qty(count($result['documents'] ?? [])), 'lines' => Format::qty($result['lines'] ?? 0)]) }}
                    @endisset
                </p>
                <p class="text-body">{{ __('imports.done.by', ['name' => $job->confirmer?->full_name, 'date' => Format::dateTime($job->confirmed_at)]) }}</p>
                @if (($result['skipped'] ?? 0) > 0)
                    <p class="text-body">{{ trans_choice('imports.done.skipped', $result['skipped'], ['count' => Format::qty($result['skipped'])]) }}</p>
                @endif
                @if (! empty($result['documents']))
                    <ul class="mt-1 flex flex-wrap gap-2">
                        @foreach ($result['documents'] as $reference)
                            <li><a href="{{ route('opening-balance.show', $reference) }}" wire:navigate dir="ltr" class="tabular font-semibold underline underline-offset-2">{{ $reference }}</a></li>
                        @endforeach
                    </ul>
                @elseif (isset($result['items']))
                    <a href="{{ route('items') }}" wire:navigate class="mt-1 text-body font-semibold underline underline-offset-2">{{ __('imports.done.see_items') }}</a>
                @endif
            </section>
        @else
            {{-- Step 2: the counters, only the rows with problems, and Import. --}}
            <dl class="grid gap-4 sm:grid-cols-3">
                @foreach ([['ready', 'completed', 'check_circle'], ['warnings', 'pending', 'warning'], ['errors', 'rejected', 'cancel']] as [$key, $tone, $icon])
                    <div class="flex items-center gap-4 rounded-xl border p-card {{ config('ui.statuses.'.$tone) }}">
                        <x-icon :name="$icon" class="size-8 shrink-0" />
                        <div>
                            <dd class="text-h1 font-semibold tabular">{{ Format::qty($counts[$key]) }}</dd>
                            <dt class="text-body font-semibold">{{ __('imports.check.'.$key) }}</dt>
                        </div>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($problem)
            <p class="flex items-center gap-2 rounded-lg border px-4 py-3 text-body font-semibold {{ config('ui.statuses.rejected') }}" role="alert">
                <x-icon name="warning" />{{ $problem }}
            </p>
        @endif

        @if ($counts['warnings'] + $counts['errors'] > 0 || $job->status === 'VALIDATED')
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-h3 font-semibold text-fg">{{ $counts['warnings'] + $counts['errors'] > 0 ? __('imports.check.problems_heading') : __('imports.check.no_problems') }}</h2>
                <div class="flex flex-wrap gap-3">
                    @if ($counts['warnings'] + $counts['errors'] > 0)
                        <a href="{{ route('imports.errors', $job->id) }}" download class="flex h-control items-center gap-2 rounded-lg border border-control bg-card px-4 text-body font-semibold text-fg shadow-card hover:bg-card-hover">
                            <x-icon name="download" class="text-muted" />{{ __('imports.check.download_errors') }}
                        </a>
                    @endif
                    @if ($job->status === 'VALIDATED')
                        <x-submit-button target="recheck" action="recheck" icon="history" variant="outline" :busy="__('app.loading.list')">
                            {{ __('imports.check.check_again') }}
                        </x-submit-button>
                    @endif
                </div>
            </div>
        @endif

        @if ($problems && $problems->total() > 0)
            <x-data-table
                :columns="[
                    'row' => ['label' => __('imports.check.row')],
                    ...collect($keyColumns)->mapWithKeys(fn ($column) => [$column->key => ['label' => $column->label()]])->all(),
                    'message' => ['label' => ''],
                ]"
                :paginator="$problems"
            >
                @foreach ($problems as $row)
                    <tr wire:key="problem-{{ $row->id }}" class="align-top">
                        <x-data-table.cell col="row" class="whitespace-nowrap">
                            <span class="font-semibold text-fg tabular">{{ Format::qty($row->row_no) }}</span>
                        </x-data-table.cell>
                        @foreach ($keyColumns as $column)
                            @if (str_ends_with($column->key, '_code'))
                                {{-- Codes stay on one line, left to right. --}}
                                <x-data-table.cell :col="$column->key"><span dir="ltr" class="whitespace-nowrap font-semibold text-fg tabular">{{ $row->payload['values'][$column->key] ?? '' }}</span></x-data-table.cell>
                            @else
                                <x-data-table.cell :col="$column->key"><bdi class="text-fg">{{ $row->payload['values'][$column->key] ?? '' }}</bdi></x-data-table.cell>
                            @endif
                        @endforeach
                        <x-data-table.cell col="message">
                            <span class="inline-flex rounded-md border px-2 py-0.5 text-small font-semibold {{ config('ui.statuses.'.($row->status === 'ERROR' ? 'rejected' : 'pending')) }}">
                                {{ __('imports.check.'.($row->status === 'ERROR' ? 'error' : 'warning')) }}
                            </span>
                            <p class="mt-1 text-body text-fg">{{ $row->text() }}</p>
                        </x-data-table.cell>
                        <td class="pe-edge"></td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif

        @if ($job->status === 'VALIDATED')
            {{-- Step 3: nothing is saved before this button. --}}
            <div class="sticky bottom-0 z-20 -mx-page flex flex-wrap items-center justify-between gap-4 border-t border-default bg-card px-page py-2.5 shadow-pop">
                <p class="text-body text-muted">
                    {{ $counts['errors'] > 0 ? trans_choice('imports.check.errors_left_out', $counts['errors'], ['count' => Format::qty($counts['errors'])]) : __('imports.check.nothing_saved_yet') }}
                </p>
                <div class="flex items-center gap-3">
                    <a href="{{ route('imports.create', $import->slug()) }}" wire:navigate class="flex h-control items-center rounded-lg px-4 text-body font-semibold text-fg hover:bg-card-hover">{{ __('imports.check.upload_another') }}</a>
                    <x-submit-button target="import" action="import" icon="check" :disabled="$importable === 0">
                        {{ trans_choice('imports.check.import_button', $importable, ['count' => Format::qty($importable)]) }}
                    </x-submit-button>
                </div>
            </div>
        @endif
    @endif
</div>
