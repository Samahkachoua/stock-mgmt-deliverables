@php
    $title = __($item['label']);
    $colours = $item['type'] ? config('ui.types.'.$item['type']) : null;
@endphp

<x-layouts.app :title="$title">
    <div class="flex items-center gap-4">
        <div @class(['grid size-10 shrink-0 place-items-center rounded-lg', $colours['soft'] ?? 'bg-card-hover'])>
            <x-icon :name="$item['icon']" :class="'size-6 '.($colours['icon'] ?? 'text-muted')" />
        </div>
        <h1 class="text-h1 font-semibold tracking-tight text-fg">{{ $title }}</h1>
    </div>

    <div class="mt-section flex flex-col items-center rounded-xl border border-default bg-card px-edge py-12 text-center shadow-card">
        <x-icon name="construction" class="size-10 text-muted" />
        <h2 class="mt-4 text-lead font-semibold text-fg">{{ __('pages.coming_soon_title') }}</h2>
        <p class="mt-1 max-w-md text-body text-muted">{{ __('pages.coming_soon_body', ['page' => $title]) }}</p>
    </div>
</x-layouts.app>
