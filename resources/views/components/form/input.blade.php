{{--
    Labelled 44px input bound to a Livewire property, with its error shown in red underneath.
    <x-form.input model="name" :label="__('...')" required />
    `suffix` shows a unit inside the field; `ltr` keeps codes and emails left-to-right in Arabic.
--}}
@props([
    'model',
    'label',
    'type' => 'text',
    'hint' => null,
    'suffix' => null,
    'ltr' => false,
    'required' => false,
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
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            wire:model.blur="{{ $model }}"
            @if ($ltr) dir="ltr" @endif
            @if ($required) required @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'h-control w-full rounded-lg border bg-card px-3 text-body text-fg placeholder:text-subtle focus:outline-none focus:ring-2',
                'text-start' => ! $ltr,
                'pe-16' => $suffix,
                'border-danger focus:ring-danger/20' => $error,
                'border-control focus:border-brand focus:ring-brand/20' => ! $error,
            ]) }}
        >
        @if ($suffix)
            <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-small font-semibold text-muted">{{ $suffix }}</span>
        @endif
    </div>
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1 text-body text-danger" role="alert">{{ $error }}</p>
    @endif
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-body text-muted">{{ $hint }}</p>
    @endif
</div>
