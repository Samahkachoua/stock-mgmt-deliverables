{{-- Transaction type chip in the type's colour. <x-type-badge code="OUT" /> --}}
@props(['code'])

@php($type = \App\Support\Transactions\TransactionTypes::get($code))

<span {{ $attributes->class(['inline-flex h-6 items-center gap-1.5 rounded-md px-2 text-small font-semibold text-fg', $type->colours()['soft']]) }}>
    <x-icon :name="$type->icon()" @class(['size-4', $type->colours()['icon']]) />
    {{ __('types.'.$type->token()) }}
</span>
