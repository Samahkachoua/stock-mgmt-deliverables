{{-- Document status, labelled and coloured through App\Support\Transactions\Statuses. <x-status-badge code="POSTED" :type="$ui" /> (the type gives its own labels, e.g. Delivered) --}}
@props(['code', 'type' => null])

<span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 text-small font-semibold', \App\Support\Transactions\Statuses::classes($code)]) }}>
    {{ \App\Support\Transactions\Statuses::label($code, $type) }}
</span>
