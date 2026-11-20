{{-- Priority of an order: a badge for Urgent / Low, plain text for Normal. <x-draft-priority-badge code="URGENT" /> --}}
@props(['code'])

@php($classes = \App\Support\Drafts\DraftTypes::priorityClasses($code))

@if ($classes === null)
    <span {{ $attributes->class(['whitespace-nowrap text-fg']) }}>{{ \App\Support\Drafts\DraftTypes::priorityLabel($code) }}</span>
@else
    <x-badge :attributes="$attributes" :classes="$classes">{{ \App\Support\Drafts\DraftTypes::priorityLabel($code) }}</x-badge>
@endif
