{{-- Transaction type chip, filled in the type's colour with white text. <x-type-badge code="OUT" /> --}}
@props(['code'])

@php($type = \App\Support\Transactions\TransactionTypes::get($code))

<span {{ $attributes->class(['inline-flex h-badge items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 text-small font-bold text-on-brand shadow-badge', $type->colours()['strong']]) }}>
    <x-icon :name="$type->icon()" class="size-4" />
    {{ __('types.'.$type->token()) }}
</span>
