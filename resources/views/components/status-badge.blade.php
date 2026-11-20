{{-- Document status, labelled and coloured through App\Support\Transactions\Statuses. <x-status-badge code="POSTED" :type="$ui" /> (the type gives its own labels, e.g. Delivered) --}}
@props(['code', 'type' => null])

<x-badge :attributes="$attributes" :classes="\App\Support\Transactions\Statuses::classes($code)">{{ \App\Support\Transactions\Statuses::label($code, $type) }}</x-badge>
