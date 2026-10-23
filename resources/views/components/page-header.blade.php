{{--
    Page title with the type's icon and colour, an optional back link, a badge
    next to the title (e.g. status) and actions on the end side.
    <x-page-header :type="$type" :title="..." :subtitle="..." :back="$url" :back-label="...">
        <x-slot:badge>...</x-slot:badge>
        <x-slot:actions>...</x-slot:actions>
    </x-page-header>
--}}
@props([
    'title',
    'type' => null,
    'icon' => null,
    'subtitle' => null,
    'back' => null,
    'backLabel' => null,
    'ltr' => false, // titles that are references (DN-2026-000123) stay left-to-right
])

@php
    $colours = $type?->colours();
    $icon ??= $type?->icon();
@endphp

<div {{ $attributes->class('flex flex-col gap-2') }}>
    @if ($back)
        <a href="{{ $back }}" wire:navigate class="-ms-1 inline-flex h-control items-center gap-2 self-start rounded-lg px-1 text-body font-semibold text-muted hover:text-fg print:hidden">
            <x-icon name="arrow_back" class="rtl:-scale-x-100" />
            {{ $backLabel }}
        </a>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @if ($icon)
                <div @class(['grid size-10 shrink-0 place-items-center rounded-lg', $colours['soft'] ?? 'bg-card-hover'])>
                    <x-icon :name="$icon" @class(['size-6', $colours['icon'] ?? 'text-muted']) />
                </div>
            @endif
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 @if ($ltr) dir="ltr" @endif @class(['break-words text-h1 font-semibold tracking-tight text-fg', 'tabular' => $ltr])>{{ $title }}</h1>
                    {{ $badge ?? '' }}
                </div>
                @if ($subtitle)
                    <p class="text-body text-muted">{{ $subtitle }}</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2 print:hidden">{{ $actions }}</div>
        @endisset
    </div>
</div>
