@props(['compact' => false])

{{--
    Logo block. The ETL logo reads on light and dark backgrounds, so one image serves both themes.
    `compact` is the sidebar: the ETL mark; on a tablet (icons only) the parcel icon.
    Otherwise (login) the full logo with the Arabic and English company names.
--}}
@if ($compact)
    <div {{ $attributes->class(['flex min-w-0 items-center']) }}>
        <img
            src="{{ asset('images/brand/etl-mark.png') }}"
            srcset="{{ asset('images/brand/etl-mark@2x.png') }} 2x"
            width="107" height="24"
            alt="{{ __('app.logo_alt') }}"
            class="h-6 w-auto shrink-0 md:hidden xl:block"
        >
        <img
            src="{{ asset('images/brand/etl-icon.png') }}"
            srcset="{{ asset('images/brand/etl-icon@2x.png') }} 2x"
            width="40" height="32"
            alt="{{ __('app.logo_alt') }}"
            class="hidden h-8 w-auto shrink-0 md:block xl:hidden"
        >
    </div>
@else
    <div {{ $attributes->class(['flex flex-col items-center gap-2']) }}>
        <img
            src="{{ asset('images/brand/etl-logo.png') }}"
            srcset="{{ asset('images/brand/etl-logo@2x.png') }} 2x"
            width="240" height="95"
            alt="{{ __('app.logo_alt') }}"
            class="h-auto w-52"
        >
    </div>
@endif
