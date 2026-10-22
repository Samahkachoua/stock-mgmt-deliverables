{{-- Draft status (Draft / Confirmed / Cancelled), labelled and coloured through App\Support\Drafts\DraftTypes. <x-draft-status-badge code="DRAFT" /> --}}
@props(['code'])

<span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 text-small font-semibold', \App\Support\Drafts\DraftTypes::statusClasses($code)]) }}>
    {{ \App\Support\Drafts\DraftTypes::statusLabel($code) }}
</span>
