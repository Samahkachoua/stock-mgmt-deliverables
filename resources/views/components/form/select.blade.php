{{--
    Labelled 44px dropdown bound to a Livewire property, with its error shown in red underneath.
    <x-form.select model="preferred_lang" :label="__('...')" :options="['en' => 'English']" required />
--}}
@props([
    'model',
    'label',
    'options' => [],
    'hint' => null,
    'required' => false,
    'disabled' => false,
])

@php
    $id = 'field-'.str_replace('.', '-', $model);
    $error = $errors->first($model);
    $describedBy = trim(($error ? $id.'-error ' : '').($hint ? $id.'-hint' : ''));
@endphp

<div>
    <label for="{{ $id }}" class="mb-1 block text-body font-semibold text-fg">
        {{ $label }}
        @if ($required)
            <span class="text-danger" aria-hidden="true">*</span>
        @endif
    </label>
    <div class="relative">
        <select
            id="{{ $id }}"
            wire:model.live="{{ $model }}"
            @if ($required) required @endif
            @disabled($disabled)
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'h-control w-full appearance-none rounded-lg border bg-card pe-10 ps-3 text-start text-body text-fg focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-surface disabled:text-muted',
                'border-danger focus:ring-danger/20' => $error,
                'border-control focus:border-brand focus:ring-brand/20' => ! $error,
            ]) }}
        >
            @foreach ($options as $value => $text)
                <option value="{{ $value }}">{{ $text }}</option>
            @endforeach
        </select>
        <x-icon name="keyboard_arrow_down" class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-muted" />
    </div>
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1 text-body text-danger" role="alert">{{ $error }}</p>
    @endif
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-body text-muted">{{ $hint }}</p>
    @endif
</div>
