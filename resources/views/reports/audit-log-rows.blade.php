{{--
    Audit log entries as cards (AuditLogReport::rowsView()): what was done to
    which record, by whom and when, then each changed field with its value
    before and after ("—" when that snapshot doesn't have it).
    `rows`: a page of Report::paginate(), each with `details` and `link`.
--}}

@foreach ($rows as $row)
    @php($entry = $row['details'])
    <article wire:key="audit-entry-{{ $rows->firstItem() + $loop->index }}" class="rounded-xl border border-default bg-card p-card shadow-card">
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-body text-muted [overflow-wrap:anywhere]">
                    {{ $entry['entity'] }}
                    @if ($row['link'])
                        <a href="{{ $row['link'] }}" wire:navigate class="font-semibold text-brand hover:underline"><bdi @if ($entry['ltr']) dir="ltr" @endif>{{ $entry['record'] }}</bdi></a>
                    @else
                        <bdi class="font-semibold text-fg" @if ($entry['ltr']) dir="ltr" @endif>{{ $entry['record'] }}</bdi>
                    @endif
                </h2>
                <p class="mt-1 text-small text-muted">
                    {!! __('reports.audit_log.entry_by', [
                        'name' => '<bdi>'.e($entry['who']).'</bdi>',
                        'when' => '<time datetime="'.e($entry['at']).'" class="tabular">'.e($entry['when']).'</time>',
                    ]) !!}
                </p>
            </div>
            {{-- The action as a filled pill, like the status badges of the lists; at the end (left in Arabic). --}}
            <x-badge :tone="$entry['tone']" class="shrink-0">{{ $entry['action'] }}</x-badge>
        </header>

        @if ($entry['changes'] === [])
            <p class="mt-3 text-body text-muted">{{ __('reports.audit_log.no_changes') }}</p>
        @else
            <div class="mt-3 overflow-hidden rounded-lg border border-default">
                <table class="w-full table-fixed text-table">
                    <thead>
                        <tr class="h-thead border-b border-default bg-surface text-start font-semibold text-muted">
                            <th scope="col" class="w-1/4 px-cell text-start">{{ __('reports.audit_log.field') }}</th>
                            <th scope="col" class="px-cell text-start">{{ __('reports.audit_log.old') }}</th>
                            <th scope="col" class="px-cell text-start">{{ __('reports.audit_log.new') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-default">
                        @foreach ($entry['changes'] as $change)
                            <tr class="h-row align-top">
                                <th scope="row" class="px-cell py-2 text-start font-medium text-fg [overflow-wrap:anywhere]">{{ $change['field'] }}</th>
                                <td class="px-cell py-2 text-muted [overflow-wrap:anywhere]"><bdi>{{ $change['old'] ?? '—' }}</bdi></td>
                                <td class="px-cell py-2 text-fg [overflow-wrap:anywhere]"><bdi>{{ $change['new'] ?? '—' }}</bdi></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
@endforeach
