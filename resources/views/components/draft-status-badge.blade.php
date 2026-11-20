{{-- Draft status (Draft / Confirmed / Cancelled), labelled and coloured through App\Support\Drafts\DraftTypes. <x-draft-status-badge code="DRAFT" /> --}}
@props(['code'])

<x-badge :attributes="$attributes" :classes="\App\Support\Drafts\DraftTypes::statusClasses($code)">{{ \App\Support\Drafts\DraftTypes::statusLabel($code) }}</x-badge>
