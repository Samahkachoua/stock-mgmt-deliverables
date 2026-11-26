@props(['title' => null])

{{--
    The app frame (CLAUDE.md "Responsive layout"):
      desktop (xl)  full sidebar; the top bar's burger closes it to icons (remembered per browser)
      tablet (md)   sidebar as icons, labels as tooltips; the burger opens it in full over the page
      phone         sidebar hidden; the burger opens it as a drawer
                    (from the start side: the right in Arabic)
    The burger is always shown, at the start of the top bar. On a tablet or phone, `nav` is
    the open drawer (`sidebar-expanded`); closed by a link, a tap outside or Esc.
--}}
<x-layouts.base :title="$title">
    <a href="#main" class="print:hidden sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:shadow-pop">
        {{ __('app.skip_to_content') }}
    </a>

    <div
        x-data="{
            nav: false,
            tip: null,
            collapsed: document.documentElement.classList.contains('sidebar-collapsed'),
            toggleSidebar() {
                this.collapsed = ! this.collapsed;
                this.tip = null;
                document.documentElement.classList.toggle('sidebar-collapsed', this.collapsed);
                try { localStorage.setItem('sidebar', this.collapsed ? 'collapsed' : 'open'); } catch (e) {}
            },
            desktop() {
                return window.matchMedia('(width >= 80rem)').matches;
            },
            burger() {
                if (this.desktop()) {
                    this.nav = false;
                    this.toggleSidebar();
                } else {
                    this.tip = null;
                    this.nav = ! this.nav;
                }
            },
            menuOpen() {
                return this.desktop() ? ! this.collapsed : this.nav;
            },
            showTip(link) {
                const box = link.getBoundingClientRect();
                this.tip = { label: link.dataset.label, pending: link.dataset.pending, top: box.top + box.height / 2 };
            },
        }"
        x-on:keydown.escape.window="nav = false; tip = null"
        x-bind:class="{ 'sidebar-expanded': nav }"
    >
        {{-- Phone and tablet: a tap outside the open sidebar closes it. --}}
        <div x-cloak x-show="nav" x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-40 bg-scrim xl:hidden print:hidden" aria-hidden="true"></div>

        {{-- Never printed (CLAUDE.md "Printing"). --}}
        <aside
            id="sidebar"
            data-sidebar
            x-bind:class="{ 'max-md:invisible': ! nav, 'max-md:translate-x-0!': nav }"
            class="fixed inset-y-0 start-0 z-50 flex w-64 flex-col border-e border-default bg-sidebar transition-[translate,visibility,width,background-color] duration-200 max-md:invisible max-md:-translate-x-full max-md:shadow-pop max-md:rtl:translate-x-full md:z-40 md:w-rail xl:w-sidebar xl:collapsed:w-rail md:max-xl:expanded:w-sidebar md:max-xl:expanded:shadow-pop print:hidden"
        >
            <div class="flex h-topbar shrink-0 items-center justify-between gap-2 border-b border-default px-4 md:justify-center md:px-3 xl:justify-start xl:px-4 xl:collapsed:justify-center xl:collapsed:px-3 md:max-xl:expanded:justify-start md:max-xl:expanded:px-4">
                <x-brand compact />
                <button type="button" x-on:click="nav = false" class="grid size-control shrink-0 place-items-center rounded-lg text-muted hover:bg-card-hover hover:text-fg md:hidden">
                    <x-icon name="close" />
                    <span class="sr-only">{{ __('nav.close_menu') }}</span>
                </button>
            </div>

            <nav x-on:scroll.passive="tip = null" class="scrollbar-thin flex-1 overflow-y-auto px-3 py-3 md:px-2 xl:px-3 xl:collapsed:px-2 md:max-xl:expanded:px-3" aria-label="{{ __('nav.main') }}">
                {{-- Sections (Inbound, Outbound, …) with the Reports and Settings groups: config/ui.php `nav`. --}}
                @auth
                    <x-sidebar.nav :sections="\App\Support\Navigation\Sidebar::for(auth()->user())" />
                @endauth
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
            class="pointer-events-none fixed start-rail z-50 ms-2 hidden -translate-y-1/2 rounded-lg border border-default bg-card px-2.5 py-1.5 shadow-pop md:block xl:hidden xl:collapsed:block md:max-xl:expanded:hidden print:hidden"
        >
            <p class="whitespace-nowrap text-small font-semibold text-fg" x-text="tip?.label"></p>
            <p x-show="tip?.pending" class="whitespace-nowrap text-small text-muted" x-text="tip?.pending"></p>
        </div>

        <div class="flex min-h-screen min-w-0 flex-col md:ps-rail xl:ps-sidebar xl:collapsed:ps-rail transition-[padding] duration-200 print:ps-0">
            {{-- Phone: menu button, page title, user menu (with theme and language). No warehouse here: pages that need one have their own field or filter. --}}
            <header class="sticky top-0 z-30 flex h-topbar items-center gap-2 border-b border-default bg-canvas transition-colors duration-200 px-3 md:justify-between md:gap-3 md:px-4 xl:px-page print:hidden">
                {{--
                    Always shown, styled like the theme and language buttons. Desktop: closes the sidebar
                    to its icons, or opens it again (remembered per browser). Tablet: opens the full
                    sidebar over the page. Phone: opens the drawer.
                --}}
                <button
                    type="button"
                    data-menu-button
                    data-sidebar-toggle
                    x-on:click="burger()"
                    x-bind:aria-expanded="menuOpen().toString()"
                    x-bind:aria-label="desktop() ? (collapsed ? @js(__('nav.expand_sidebar')) : @js(__('nav.collapse_sidebar'))) : (nav ? @js(__('nav.close_menu')) : @js(__('nav.open_menu')))"
                    x-bind:title="desktop() ? (collapsed ? @js(__('nav.expand_sidebar')) : @js(__('nav.collapse_sidebar'))) : (nav ? @js(__('nav.close_menu')) : @js(__('nav.open_menu')))"
                    aria-label="{{ __('nav.open_menu') }}"
                    aria-controls="sidebar"
                    class="grid size-control shrink-0 place-items-center rounded-lg border border-default bg-card text-fg transition-colors hover:bg-card-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                >
                    <x-icon name="menu" class="size-5" />
                </button>
                <p class="min-w-0 flex-1 truncate text-body font-semibold text-fg md:hidden" aria-hidden="true">{{ $title }}</p>

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
                    {{-- Notifications: what waits for the user, on every size. --}}
                    @auth
                        <livewire:notification-bell />
                    @endauth
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
