{{--
    The three steps of an import, the current one highlighted.
    <x-import.steps :current="2" />
--}}
@props(['current'])

<ol class="flex flex-wrap items-center gap-2 text-body" aria-label="{{ __('imports.steps.label') }}">
    @foreach ([1 => 'upload', 2 => 'check', 3 => 'import'] as $number => $step)
        <li class="flex items-center gap-2" @if ($number === $current) aria-current="step" @endif>
            <span @class([
                'grid size-8 place-items-center rounded-full text-body font-semibold tabular',
                'bg-brand text-on-brand' => $number === $current,
                'border border-default bg-card text-muted' => $number > $current,
                'border '.config('ui.statuses.completed') => $number < $current,
            ])>
                @if ($number < $current)
                    <x-icon name="check" class="size-4" />
                @else
                    {{ \App\Support\Format::qty($number) }}
                @endif
            </span>
            <span @class(['font-semibold text-fg' => $number === $current, 'text-muted' => $number !== $current])>{{ __('imports.steps.'.$step) }}</span>
            @unless ($loop->last)
                <x-icon name="chevron_right" class="size-4 text-subtle rtl:-scale-x-100" />
            @endunless
        </li>
    @endforeach
</ol>
