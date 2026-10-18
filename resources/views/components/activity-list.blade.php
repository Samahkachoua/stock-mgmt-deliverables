{{--
    What happened to a document, oldest first (from audit_log once documents are real).
    <x-activity-list :entries="[['event' => 'created', 'by' => 'Rami K.', 'at' => $carbon, 'state' => 'done'], ...]" />
    state: done | success | progress | danger
--}}
@props(['entries'])

@php
    $states = [
        'done' => ['icon' => 'radio_button_checked', 'class' => 'text-fg'],
        'success' => ['icon' => 'check_circle', 'class' => 'text-status-completed'],
        'progress' => ['icon' => 'pending', 'class' => 'text-status-transit'],
        'danger' => ['icon' => 'cancel', 'class' => 'text-status-rejected'],
    ];
@endphp

<section {{ $attributes->class('rounded-xl border border-default bg-card p-card shadow-card') }}>
    <h2 class="flex items-center gap-2 text-small font-semibold uppercase tracking-wide text-muted">
        <x-icon name="history" class="text-muted" />
        {{ __('transactions.activity.heading') }}
    </h2>

    @if (empty($entries))
        <p class="mt-3 text-body text-muted">{{ __('transactions.activity.empty') }}</p>
    @else
        <ol class="mt-3 flex flex-col">
            @foreach ($entries as $entry)
                @php($state = $states[$entry['state']] ?? $states['done'])
                <li class="relative flex items-start gap-3 pb-4 last:pb-0">
                    @unless ($loop->last)
                        <span class="absolute start-2.5 top-6 bottom-0 w-px bg-default" aria-hidden="true"></span>
                    @endunless
                    <x-icon :name="$state['icon']" @class(['relative', $state['class']]) />
                    <div class="flex flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <p class="text-body text-fg">
                            <span class="font-semibold">{!! __('transactions.activity.'.$entry['event'], ['name' => '<bdi>'.e($entry['by']).'</bdi>']) !!}</span>
                        </p>
                        <time datetime="{{ $entry['at']->toIso8601String() }}" class="text-body text-muted tabular">{{ \App\Support\Format::dateTime($entry['at']) }}</time>
                        @if (filled($entry['reason'] ?? null))
                            {{-- Why it was cancelled or rejected. --}}
                            <p class="w-full text-body text-muted">{!! __('transactions.activity.reason', ['reason' => '<bdi class="text-fg">'.e($entry['reason']).'</bdi>']) !!}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
