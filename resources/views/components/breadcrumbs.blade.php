{{--
    Where the page sits: Home › Delivery notes › DN-2026-000123.
    <x-breadcrumbs :items="[[__('drafts.types.order.title'), route('orders')]]" :current="$reference" ltr />
    `items`: the pages above this one, [label, url|null] each (Home is added first);
    `current`: this page's name (not a link); `ltr`: the current name is a reference.
--}}
@props(['items' => [], 'current', 'ltr' => false])

@php
    $home = auth()->user()?->can('stock.view') ? route('home') : null;
    $trail = [[__('nav.home'), $home], ...$items];
@endphp

<nav {{ $attributes->class('min-w-0 print:hidden') }} aria-label="{{ __('app.breadcrumb') }}" data-breadcrumbs>
    <ol class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-body text-muted">
        @foreach ($trail as [$label, $url])
            <li class="flex min-w-0 items-center gap-1.5">
                @if ($url)
                    <a href="{{ $url }}" wire:navigate class="truncate rounded font-medium hover:text-fg hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40">{{ $label }}</a>
                @else
                    <span class="truncate font-medium">{{ $label }}</span>
                @endif
                <x-icon name="chevron_right" class="size-4 shrink-0 text-subtle rtl:-scale-x-100" aria-hidden="true" />
            </li>
        @endforeach
        <li class="min-w-0">
            <span aria-current="page" @if ($ltr) dir="ltr" @endif @class(['block truncate font-semibold text-fg', 'tabular' => $ltr])>{{ $current }}</span>
        </li>
    </ol>
</nav>
