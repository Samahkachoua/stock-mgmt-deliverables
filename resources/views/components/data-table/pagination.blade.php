{{--
    "Showing 1 to 8 of 84" + Previous / pages / Next, for a Livewire component using WithPagination.
    Phone: Previous / Next with "Page 2 of 9" between them (CLAUDE.md "Responsive layout").
--}}
@props(['paginator'])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pages = collect([1, $current - 1, $current, $current + 1, $last])
        ->filter(fn ($page) => $page >= 1 && $page <= $last)
        ->unique()
        ->sort()
        ->values();
    $button = 'inline-flex h-control min-w-control items-center justify-center gap-1 rounded-lg px-2.5 text-body font-semibold';
    $strong = fn ($value) => '<span class="font-semibold text-fg">'.e(\App\Support\Format::qty($value)).'</span>';
@endphp

<nav class="flex flex-wrap items-center justify-between gap-4 border-t border-default px-card py-2 md:px-edge" aria-label="{{ __('transactions.list.pagination') }}">
    <p @class(['text-body text-muted', 'max-md:hidden' => $paginator->hasPages()])>
        {!! __('transactions.list.showing', [
            'from' => $strong($paginator->firstItem()),
            'to' => $strong($paginator->lastItem()),
            'total' => $strong($paginator->total()),
        ]) !!}
    </p>

    @if ($paginator->hasPages())
        <div class="flex items-center gap-1 max-md:w-full max-md:justify-between">
            <button type="button" wire:click="previousPage" @disabled($paginator->onFirstPage()) class="{{ $button }} text-fg hover:bg-card-hover disabled:cursor-not-allowed disabled:text-subtle disabled:hover:bg-transparent">
                <x-icon name="chevron_left" class="size-4 rtl:-scale-x-100" />
                {{ __('transactions.list.previous') }}
            </button>

            <p data-page-of class="text-body font-semibold text-fg md:hidden">
                {{ __('transactions.list.page_of', ['page' => \App\Support\Format::qty($current), 'pages' => \App\Support\Format::qty($last)]) }}
            </p>

            @foreach ($pages as $i => $page)
                @if ($i > 0 && $page - $pages[$i - 1] > 1)
                    <span class="px-1 text-muted max-md:hidden" aria-hidden="true">&hellip;</span>
                @endif
                <button
                    type="button"
                    wire:click="gotoPage({{ $page }})"
                    @if ($page === $current) aria-current="page" @endif
                    @class([$button, 'max-md:hidden', 'bg-brand text-on-brand' => $page === $current, 'text-fg hover:bg-card-hover' => $page !== $current])
                >{{ \App\Support\Format::qty($page) }}</button>
            @endforeach

            <button type="button" wire:click="nextPage" @disabled(! $paginator->hasMorePages()) class="{{ $button }} text-fg hover:bg-card-hover disabled:cursor-not-allowed disabled:text-subtle disabled:hover:bg-transparent">
                {{ __('transactions.list.next') }}
                <x-icon name="chevron_right" class="size-4 rtl:-scale-x-100" />
            </button>
        </div>
    @endif
</nav>
