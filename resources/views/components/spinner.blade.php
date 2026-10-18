{{--
    The app's only spinner (CLAUDE.md "Loading states"). Drawn in the text
    colour, so it follows the theme and the button it sits in.
    <x-spinner />
--}}
<svg {{ $attributes->class('size-4 shrink-0 animate-spin') }} viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
</svg>
