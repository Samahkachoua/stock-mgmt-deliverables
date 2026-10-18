{{--
    Read-only header facts of a document, in two columns (one on a phone).
    <x-transaction-summary :rows="[['label' => ..., 'value' => ..., 'ltr' => false], ...]" />
--}}
@props(['rows'])

@php($columns = collect($rows)->filter(fn ($row) => filled($row['value']))->values()->split(2))

<section {{ $attributes->class('grid gap-x-12 rounded-xl border border-default bg-card px-card py-1 shadow-card max-md:divide-y max-md:divide-default md:grid-cols-2 md:py-card-y') }}>
    @foreach ($columns as $column)
        <dl class="divide-y divide-default">
            @foreach ($column as $row)
                <div class="flex items-baseline justify-between gap-4 py-cell-y">
                    <dt class="shrink-0 text-small font-semibold uppercase tracking-wide text-muted">{{ $row['label'] }}</dt>
                    <dd class="min-w-0 break-words text-end text-body font-semibold text-fg">
                        @if ($row['ltr'] ?? false)
                            <span dir="ltr" class="tabular">{{ $row['value'] }}</span>
                        @else
                            <bdi>{{ $row['value'] }}</bdi>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endforeach
</section>
