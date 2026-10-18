@props(['compact' => false])

{{-- Logo block from the Stitch sidebar. `compact` hides the text on a tablet, where the sidebar is icons only. --}}
<div {{ $attributes->class(['flex items-center gap-3']) }}>
    <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand text-on-brand shadow-card">
        <x-icon name="warehouse" />
    </div>
    <div @class(['flex flex-col leading-tight', 'md:hidden xl:flex' => $compact])>
        <span class="text-lead font-semibold tracking-tight text-fg">{{ config('app.name') }}</span>
        <span class="text-small font-semibold uppercase tracking-wider text-muted">{{ __('app.tagline') }}</span>
    </div>
</div>
