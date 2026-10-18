{{-- Document status, labelled and coloured through App\Support\Transactions\Statuses. <x-status-badge code="POSTED" /> --}}
@props(['code'])

<span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 text-small font-semibold', \App\Support\Transactions\Statuses::classes($code)]) }}>
    {{ \App\Support\Transactions\Statuses::label($code) }}
</span>
