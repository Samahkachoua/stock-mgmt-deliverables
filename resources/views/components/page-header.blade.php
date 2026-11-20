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
    'crumbs' => null,
    'crumb' => null,
    'breadcrumbs' => true,
])

@php
    $colours = $type?->colours();
    $icon ??= $type?->icon();
    $crumbs ??= $back ? [[$backLabel, $back]] : [];
    // A custom current name ("Edit") is plain text; the title itself keeps its direction.
    $crumbLtr = $crumb === null && $ltr;
    $crumb ??= $title;
@endphp

<div {{ $attributes->class('flex flex-col gap-2') }}>
    @if ($breadcrumbs)
        <x-breadcrumbs :items="$crumbs" :current="$crumb" :ltr="$crumbLtr" @class(['max-md:hidden' => (bool) $back]) />
    @endif

    @if ($back)
        <a href="{{ $back }}" wire:navigate class="-ms-1 inline-flex md:hidden h-control items-center gap-2 self-start rounded-lg px-1 text-body font-semibold text-muted hover:text-fg print:hidden">
            <x-icon name="arrow_back" class="rtl:-scale-x-100" />
            {{ $backLabel }}
        </a>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 @if ($ltr) dir="ltr" @endif @class(['break-words text-h1 font-bold tracking-tight text-fg', 'tabular' => $ltr])>{{ $title }}</h1>
                    {{ $badge ?? '' }}
                </div>
                @if ($subtitle)
                    <p class="mt-1 text-body text-muted">{{ $subtitle }}</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div class="flex flex-wrap items-center gap-2 print:hidden">{{ $actions }}</div>
        @endisset
    </div>
</div>
