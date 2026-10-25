{{-- Priority of an order: a badge for Urgent / Low, plain text for Normal. <x-draft-priority-badge code="URGENT" /> --}}
@props(['code'])

@php($classes = \App\Support\Drafts\DraftTypes::priorityClasses($code))

@if ($classes === null)
    <span {{ $attributes->class(['whitespace-nowrap text-fg']) }}>{{ \App\Support\Drafts\DraftTypes::priorityLabel($code) }}</span>
@else
    <span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 text-small font-semibold', $classes]) }}>
        {{ \App\Support\Drafts\DraftTypes::priorityLabel($code) }}
    </span>
@endif
