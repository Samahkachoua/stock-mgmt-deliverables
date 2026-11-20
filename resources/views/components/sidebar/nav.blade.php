@props(['sections'])

{{--
    The sidebar's entries (App\Support\Navigation\Sidebar::for()), in sections.
      full sidebar (desktop, tablet drawer, phone drawer): a small uppercase heading above each section
      icon rail (tablet, collapsed desktop): a thin line between sections, labels as tooltips
    "Full" and "rail" parts are swapped by the same responsive classes the labels use.
--}}
@php
    $fullOnly = 'md:hidden xl:block xl:collapsed:hidden md:max-xl:expanded:block';
    $railOnly = 'hidden md:block xl:hidden xl:collapsed:block md:max-xl:expanded:hidden';
@endphp

<div class="flex flex-col gap-1">
    @foreach ($sections as $s => $section)
        <div @if ($section['label'] !== null) role="group" aria-labelledby="nav-section-{{ $s }}" @endif>
            @if ($section['label'] !== null)
                {{-- 12px is the smallest text size (CLAUDE.md "Density"); lighter weight and spacing keep it quiet. --}}
                <p id="nav-section-{{ $s }}" class="{{ $fullOnly }} truncate px-3 pb-0.5 pt-2.5 text-small font-medium uppercase tracking-wider text-muted rtl:tracking-normal">{{ $section['label'] }}</p>
            @elseif (! $loop->first)
                {{-- A section without a heading (Reports) still stands apart from the one above. --}}
                <div class="{{ $fullOnly }} h-2.5" aria-hidden="true"></div>
            @endif
            @if (! $loop->first)
                <div class="{{ $railOnly }} mx-1 my-2 border-t border-default" aria-hidden="true"></div>
            @endif

            <ul class="flex flex-col gap-0.5">
                @foreach ($section['items'] as $item)
                    <li>
                        <x-sidebar.link :item="$item" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
