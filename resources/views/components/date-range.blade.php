{{--
    The "Date from" / "Date to" pair of a Livewire list (CLAUDE.md "List state"):
    two date pickers, empty by default, bound to the component's `dateFrom` and
    `dateTo` (App\Support\DateRange). In Arabic "from" sits on the right.
    <x-date-range :error="$dateError" />
    `error`: "Date from" is after "Date to": both fields are outlined in red.
--}}
@props(['error' => false, 'from' => 'dateFrom', 'to' => 'dateTo'])

@foreach ([$from => __('transactions.filters.date_from'), $to => __('transactions.filters.date_to')] as $model => $label)
    <div {{ $attributes->class([
        'flex h-control w-full min-w-0 items-center gap-2 rounded-lg border bg-card ps-3 focus-within:ring-2',
        'border-danger focus-within:ring-danger/20' => $error,
        'border-control focus-within:border-brand focus-within:ring-brand/20' => ! $error,
    ]) }}>
        <label for="filter-{{ $model }}" class="shrink-0 text-small font-semibold text-muted">{{ $label }}</label>
        <input
            id="filter-{{ $model }}"
            type="date"
            wire:model.live="{{ $model }}"
            @if ($error) aria-invalid="true" @endif
            class="h-full w-full min-w-0 border-0 bg-transparent pe-2 text-body text-fg focus:outline-none focus:ring-0"
        >
    </div>
@endforeach
