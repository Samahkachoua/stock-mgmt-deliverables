{{--
    Read-only header facts of a document, in two columns (one on a phone).
    <x-transaction-summary :rows="[['label' => ..., 'value' => ..., 'ltr' => false, 'url' => null], ...]" />
    `url` makes the value a link (e.g. the draft a receipt was created from).
    `compact` (print): two fields per row at any width, tight rows, no card.
--}}
@props(['rows', 'compact' => false])

@php
    $filled = collect($rows)->filter(fn ($row) => filled($row['value']))->values();
    $columns = $filled->split(2);
@endphp

@if ($compact)
    <dl {{ $attributes->class('grid grid-cols-2 gap-x-8 border border-black px-2 py-1') }}>
        @foreach ($filled as $row)
            <div class="flex items-baseline gap-2 py-0.5">
                <dt class="shrink-0 text-small font-semibold">{{ $row['label'] }}:</dt>
                <dd class="min-w-0 break-words font-semibold">
                    @if ($row['ltr'] ?? false)
                        <span dir="ltr" class="tabular">{{ $row['value'] }}</span>
                    @else
                        <bdi>{{ $row['value'] }}</bdi>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@else
<section {{ $attributes->class('grid gap-x-12 rounded-xl border border-default bg-card px-card py-1 shadow-card max-md:divide-y max-md:divide-default md:grid-cols-2 md:py-card-y') }}>
    @foreach ($columns as $column)
        <dl class="divide-y divide-default">
            @foreach ($column as $row)
                <div class="flex items-baseline justify-between gap-4 py-cell-y">
                    <dt class="shrink-0 text-small font-semibold uppercase tracking-wide text-muted">{{ $row['label'] }}</dt>
                    <dd class="min-w-0 break-words text-end text-body font-semibold text-fg">
                        @if ($row['url'] ?? null)
                            <a href="{{ $row['url'] }}" wire:navigate class="text-brand hover:underline print:text-black print:no-underline" @if ($row['ltr'] ?? false) dir="ltr" @endif>{{ $row['value'] }}</a>
                        @elseif ($row['ltr'] ?? false)
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
@endif
