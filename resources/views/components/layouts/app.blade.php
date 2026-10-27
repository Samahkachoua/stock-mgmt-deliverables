@props(['title' => null])

{{--
    The app frame (CLAUDE.md "Responsive layout"):
      desktop (xl)  full sidebar
      tablet (md)   sidebar as icons, labels as tooltips
      phone         sidebar hidden; the top bar's menu button opens it as a drawer
                    (from the start side: the right in Arabic)
--}}
<x-layouts.base :title="$title">
    <a href="#main" class="print:hidden sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:shadow-pop">
        {{ __('app.skip_to_content') }}
    </a>

    <div
        x-data="{
            nav: false,
            tip: null,
            showTip(link) {
                const box = link.getBoundingClientRect();
                this.tip = { label: link.dataset.label, pending: link.dataset.pending, top: box.top + box.height / 2 };
            },
        }"
        x-on:keydown.escape.window="nav = false; tip = null"
    >
        {{-- Phone: a tap outside the drawer closes it. --}}
        <div x-cloak x-show="nav" x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-40 bg-scrim md:hidden print:hidden" aria-hidden="true"></div>

        {{-- Never printed (CLAUDE.md "Printing"). --}}
        <aside
            id="sidebar"
            data-sidebar
            x-bind:class="{ 'max-md:invisible': ! nav, 'max-md:translate-x-0!': nav }"
            class="fixed inset-y-0 start-0 z-50 flex w-64 flex-col border-e border-default bg-card transition-[translate,visibility] duration-200 max-md:invisible max-md:-translate-x-full max-md:shadow-pop max-md:rtl:translate-x-full md:z-40 md:w-rail xl:w-sidebar print:hidden"
        >
            <div class="flex h-topbar shrink-0 items-center justify-between gap-2 border-b border-default px-4 md:justify-center md:px-3 xl:justify-start xl:px-4">
                <x-brand compact />
                <button type="button" x-on:click="nav = false" class="grid size-control shrink-0 place-items-center rounded-lg text-muted hover:bg-card-hover hover:text-fg md:hidden">
                    <x-icon name="close" />
                    <span class="sr-only">{{ __('nav.close_menu') }}</span>
                </button>
            </div>

            <nav x-on:scroll.passive="tip = null" class="flex-1 overflow-y-auto px-3 py-3 md:px-2 xl:px-3" aria-label="{{ __('nav.main') }}">
                @php
                    // Sidebar badges: documents waiting for this user's approval.
                    $pendingApprovals = auth()->user() ? \App\Support\Transactions\PendingApprovals::bySlug(auth()->user()) : [];
                @endphp
                <ul class="flex flex-col gap-0.5">
                    @foreach (config('ui.nav') as $item)
                        @if (isset($item['divider']))
                            <li class="my-2 border-t border-default" role="separator"></li>
                            @continue
                        @endif

                        @cannot($item['permission'])
                            @continue
                        @endcannot

                        @php
                            $active = request()->routeIs($item['route'], $item['route'].'.*');
                            $colours = $item['type'] ? config('ui.types.'.$item['type']) : null;
                            $label = __($item['label']);
                            $pending = $pendingApprovals[$item['route']] ?? 0;
                            $pendingText = $pending > 0 ? __('nav.pending_count', ['count' => \App\Support\Format::qty($pending)]) : '';
                        @endphp
                        <li>
                            <a
                                href="{{ route($item['route']) }}"
                                data-label="{{ $label }}"
                                data-pending="{{ $pendingText }}"
                                wire:navigate
                                x-on:click="nav = false; tip = null"
                                x-on:mouseenter="showTip($el)"
                                x-on:mouseleave="tip = null"
                                x-on:focus="showTip($el)"
                                x-on:blur="tip = null"
                                @if ($active) aria-current="page" @endif
                                @class([
                                    'relative flex h-control items-center justify-start gap-3 rounded-lg px-3 text-body font-semibold transition-colors md:justify-center xl:justify-start',
                                    'text-fg' => $active,
                                    $colours['soft'] ?? 'bg-card-hover' => $active,
                                    'text-muted hover:bg-card-hover hover:text-fg' => ! $active,
                                ])
                            >
                                <x-icon :name="$item['icon']" :class="$colours['icon'] ?? 'text-muted'" />
                                <span class="md:sr-only xl:not-sr-only">{{ $label }}</span>
                                @if ($pending > 0)
                                    {{-- Waiting for this user's approval (not their own documents). --}}
                                    <span class="ms-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full border px-1.5 text-small font-semibold tabular md:absolute md:-top-0.5 md:end-0.5 md:ms-0 xl:static xl:ms-auto {{ config('ui.statuses.pending') }}">
                                        <span aria-hidden="true">{{ \App\Support\Format::qty($pending) }}</span>
                                        <span class="sr-only">{{ $pendingText }}</span>
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        {{--
            Tablet: the icon rail's labels, on hover and on keyboard focus. Outside
            the nav so its scrolling doesn't clip it; screen readers already get
            the label from the link itself.
        --}}
        <div
            x-cloak
            x-show="tip"
            x-bind:style="tip && { top: tip.top + 'px' }"
            data-sidebar-tip
            aria-hidden="true"
            class="pointer-events-none fixed start-rail z-50 ms-2 hidden -translate-y-1/2 rounded-lg border border-default bg-card px-2.5 py-1.5 shadow-pop md:block xl:hidden print:hidden"
        >
            <p class="whitespace-nowrap text-small font-semibold text-fg" x-text="tip?.label"></p>
            <p x-show="tip?.pending" class="whitespace-nowrap text-small text-muted" x-text="tip?.pending"></p>
        </div>

        <div class="flex min-h-screen min-w-0 flex-col md:ps-rail xl:ps-sidebar print:ps-0">
            {{-- Phone: menu button, page title, user menu (with theme and language). No warehouse here: pages that need one have their own field or filter. --}}
            <header class="sticky top-0 z-30 flex h-topbar items-center gap-2 border-b border-default bg-card px-3 md:justify-between md:gap-3 md:px-4 xl:px-page print:hidden">
                <button
                    type="button"
                    data-menu-button
                    x-on:click="nav = true"
                    x-bind:aria-expanded="nav.toString()"
                    aria-controls="sidebar"
                    class="grid size-control shrink-0 place-items-center rounded-lg text-fg hover:bg-card-hover md:hidden"
                >
                    <x-icon name="menu" />
                    <span class="sr-only">{{ __('nav.open_menu') }}</span>
                </button>
                <p class="min-w-0 flex-1 truncate text-lead font-semibold text-fg md:hidden" aria-hidden="true">{{ $title }}</p>

                {{-- Documents, items, customers, serials and batches (phone: a button opening a search panel). --}}
                @auth
                    <livewire:global-search />
                @endauth

                <div class="ms-auto flex shrink-0 items-center gap-2 xl:gap-4">
                    <div class="hidden items-center gap-2 md:flex xl:gap-4">
                        <livewire:theme-switcher />
                        <x-language-switch />
                        <div class="h-6 w-px bg-default" aria-hidden="true"></div>
                    </div>
                    <x-user-menu />
                </div>
            </header>

            <main id="main" class="min-w-0 flex-1 p-page">
                <div class="mx-auto w-full">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>
</x-layouts.base>
