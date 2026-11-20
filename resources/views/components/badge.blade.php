{{--
    A status label: a filled pill with white text in the status colour (config('ui.badges')).
    <x-badge tone="completed">Paid</x-badge>   tones: pending, transit, completed, rejected, cancelled, draft
    `classes` replaces the tone with ready colour classes (Statuses::classes(), DraftTypes::statusClasses()).
--}}
@props(['tone' => 'cancelled', 'classes' => null])

<span {{ $attributes->class(['inline-flex h-badge items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 text-small font-bold shadow-badge [print-color-adjust:exact]', $classes ?? config('ui.badges.'.$tone)]) }}>{{ $slot }}</span>
