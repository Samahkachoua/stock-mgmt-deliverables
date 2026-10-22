{{--
    Print layout (CLAUDE.md "Printing"): A4, no sidebar, top bar or buttons,
    always light with black text whatever the user's theme, right to left in
    Arabic. Prints itself once loaded.
    <x-layouts.print :title="..." :heading="..." :reference="...">
        <x-slot:signatures>...</x-slot:signatures>   (optional; default Prepared by / Received by)
        ...summary, lines...
    </x-layouts.print>
--}}
@props(['title', 'heading', 'reference' => null])

@php
    $user = auth()->user();
    $rtl = app()->getLocale() === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" data-theme="light" class="print-view">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>

    {{-- Tokens and fonts only: no theme script, so the page stays light. --}}
    @vite(['resources/css/app.css'])

    <style>
        @page { size: A4; margin: 12mm; }
        html.print-view { color-scheme: light; background: #fff; }
        .print-view body { background: #fff; color: #000; }
        /* Header row on every page, rows never split across pages. */
        .print-view thead { display: table-header-group; }
        .print-view tr, .print-view .avoid-break { break-inside: avoid; page-break-inside: avoid; }
        /* Cards become plain bordered blocks on paper. */
        .print-view section { box-shadow: none !important; }
        .print-view .overflow-x-auto { overflow: visible !important; }
        /* Keep the lines table's blue header when printing (browsers drop backgrounds by default). */
        .print-view .print-lines thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @media screen { .print-view .sheet { max-width: 210mm; margin: 0 auto; padding: 12mm; } }
    </style>
</head>
<body class="font-sans text-sm antialiased">
    <div class="sheet flex flex-col gap-5">
        <header class="flex items-start justify-between gap-6 border-b border-black pb-4">
            <span class="text-base font-semibold">{{ config('app.name') }}</span>
            <div class="flex flex-col items-end gap-1 text-end">
                <h1 class="text-xl font-semibold">{{ $heading }}</h1>
                @if ($reference)
                    <span dir="ltr" class="tabular text-lg font-semibold">{{ $reference }}</span>
                @endif
            </div>
        </header>

        <main class="flex flex-col gap-5">
            {{ $slot }}
        </main>

        <footer class="avoid-break mt-6 flex flex-col gap-8">
            <div class="grid grid-cols-2 gap-10">
                @isset($signatures)
                    {{ $signatures }}
                @else
                    @foreach (['print.prepared_by', 'print.received_by'] as $label)
                        <div class="flex flex-col gap-10">
                            <span class="text-xs font-semibold uppercase tracking-wide">{{ __($label) }}</span>
                            <span class="border-t border-black pt-1 text-xs">{{ __('print.signature') }}</span>
                        </div>
                    @endforeach
                @endisset
            </div>
            <p class="text-xs">
                {{ __('print.printed_by', ['name' => $user?->full_name ?? '', 'date' => \App\Support\Format::dateTime(now())]) }}
            </p>
        </footer>
    </div>

    <script>
        window.addEventListener('load', function () { window.print(); });
    </script>
</body>
</html>
