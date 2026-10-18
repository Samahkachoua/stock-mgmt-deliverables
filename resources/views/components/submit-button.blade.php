{{--
    The button that runs a Livewire action (Save, Send for approval, Confirm
    receipt, Approve, Reject, Export...). The moment its action starts it is
    disabled and shows a spinner with "Saving…"; no delay (CLAUDE.md "Loading states").

    Inside a wire:submit form:  <x-submit-button target="save" icon="check">Save</x-submit-button>
    As a wire:click button:     <x-submit-button target="approve" action="approve">Approve</x-submit-button>

    `variant`: brand (default), danger, outline, or a background class (a transaction type's colour).
    `busy`: the label while running, when "Saving…" doesn't fit (e.g. "Preparing file…").

    The wire:key changes with `disabled` on purpose: wire:loading.attr puts back the
    `disabled` it found when loading started, so a button that the same request enables
    (a file upload that makes the form valid) would be disabled again. A new key makes
    Livewire swap the button for a fresh one instead.
--}}
@props([
    'target',
    'action' => null,
    'icon' => null,
    'busy' => null,
    'variant' => 'brand',
    'disabled' => false,
])

@php
    $looks = [
        'brand' => 'bg-brand text-on-brand shadow-card hover:bg-brand-hover',
        'danger' => 'bg-type-damage-strong text-on-brand hover:opacity-90',
        'outline' => 'border border-control bg-card text-fg shadow-card hover:bg-card-hover',
    ];
    $look = $looks[$variant] ?? $variant.' text-on-brand shadow-card hover:opacity-90';
    $size = str_contains((string) $attributes->get('class'), 'text-lead') ? '' : 'text-body';
@endphp

<button
    type="{{ $action ? 'button' : 'submit' }}"
    @if ($action) wire:click="{{ $action }}" @endif
    wire:key="submit-{{ $action ?? $target }}-{{ $disabled ? 'off' : 'on' }}"
    @disabled($disabled)
    wire:loading.attr="disabled"
    wire:target="{{ $target }}"
    {{ $attributes->class([
        'flex h-control items-center justify-center rounded-lg px-4 font-semibold focus:outline-none focus:ring-2 focus:ring-brand/40 focus:ring-offset-2 focus:ring-offset-card disabled:cursor-not-allowed disabled:opacity-50',
        $size,
        $look,
    ]) }}
>
    <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex items-center gap-2">
        @if ($icon)
            <x-icon :name="$icon" :class="$variant === 'outline' ? 'text-muted' : ''" />
        @endif
        {{ $slot }}
    </span>
    <span wire:loading.inline-flex wire:target="{{ $target }}" class="items-center gap-2">
        <x-spinner />
        {{ $busy ?? __('app.loading.saving') }}
    </span>
</button>
