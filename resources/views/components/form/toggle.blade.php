{{--
    Checkbox with a label, an optional hint and its error, bound to a Livewire property.
    <x-form.toggle model="form.active" :label="..." :hint="..." :disabled="$locked" />
--}}
@props(['model', 'label', 'hint' => null, 'disabled' => false])

@php
    $id = 'field-'.str_replace('.', '-', $model);
    $error = $errors->first($model);
@endphp

<div {{ $attributes }}>
    <label for="{{ $id }}" @class(['flex min-h-control items-center gap-3', 'cursor-pointer' => ! $disabled, 'cursor-not-allowed opacity-70' => $disabled])>
        <input id="{{ $id }}" type="checkbox" wire:model.live="{{ $model }}" @disabled($disabled) class="size-5 shrink-0 rounded border-control accent-brand">
        <span class="text-body font-semibold text-fg">{{ $label }}</span>
    </label>
    @if ($hint)
        <p class="ms-8 text-body text-muted">{{ $hint }}</p>
    @endif
    @if ($error)
        <p class="ms-8 mt-1 text-body text-danger" role="alert">{{ $error }}</p>
    @endif
</div>
