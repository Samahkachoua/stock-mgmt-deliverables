@props(['compact' => false])

{{--
    Logo block: the FMC mark (a light version on dark backgrounds) + app name and tagline.
    `compact` is the sidebar: a smaller mark and name so they fit on one line in its width,
    and on a tablet (icons only) the mark alone, which then carries the name.
--}}
@php
    $mark = $compact ? 'h-4.5 w-auto md:h-auto md:w-full xl:h-4.5 xl:w-auto' : 'h-6 w-auto';
@endphp
<div {{ $attributes->class(['flex items-center', $compact ? 'gap-2' : 'gap-2.5']) }}>
    <span @class(['flex shrink-0 items-center', 'md:w-10 xl:w-auto' => $compact])>
        <img src="{{ asset('images/brand/mark.png') }}" alt="{{ $compact ? config('app.name') : '' }}" class="block dark:hidden {{ $mark }}">
        <img src="{{ asset('images/brand/mark-dark.png') }}" alt="{{ $compact ? config('app.name') : '' }}" class="hidden dark:block {{ $mark }}">
    </span>
    <div @class(['flex min-w-0 flex-col leading-tight', 'md:hidden xl:flex' => $compact])>
        <span @class(['truncate font-semibold tracking-tight text-fg', $compact ? 'text-body' : 'text-lead'])>{{ config('app.name') }}</span>
        <span class="text-small font-semibold uppercase tracking-wider text-muted">{{ __('app.tagline') }}</span>
    </div>
</div>
