@props(['title' => null, 'photo' => false])

<x-layouts.base :title="$title">
    @if ($photo)
        {{-- Sign in: form + ETLS photo side by side on desktop (app.css .auth-split). --}}
        <div class="auth-split min-h-dvh bg-card" data-auth-split>
            <div class="flex items-center justify-end gap-3 p-4 [grid-area:bar]" data-auth-bar>
                <livewire:theme-switcher />
                <x-language-switch />
            </div>

            <div class="auth-panel flex items-center justify-center overflow-hidden max-md:hidden" aria-hidden="true">
                <picture class="w-full">
                    <source srcset="{{ asset('images/brand/login-bg-cutout.webp') }}" type="image/webp">
                    <img
                        src="{{ asset('images/brand/login-bg.jpeg') }}"
                        width="1600" height="640"
                        alt=""
                        decoding="async"
                        class="auth-photo block h-auto w-full"
                    >
                </picture>
            </div>

            <main class="flex items-center justify-center px-4 pb-16 [grid-area:form] md:pt-6 xl:pt-0">
                {{ $slot }}
            </main>
        </div>

        {{-- Tablet: the photo sits above the form; when both don't fit on the screen, hide the photo. --}}
        <script>
            (function () {
                var split = document.querySelector('[data-auth-split]');
                var bar = split.querySelector('[data-auth-bar]');
                var panel = split.querySelector('.auth-panel');
                var main = split.querySelector('main');
                var form = main.firstElementChild;
                var stacked = window.matchMedia('(width >= 48rem) and (width < 80rem)');
                var fit = function () {
                    var pad = parseFloat(getComputedStyle(main).paddingTop) + parseFloat(getComputedStyle(main).paddingBottom);
                    // The photo's height comes from the width (1600 x 640), not from the page, so hiding it never loops.
                    var needed = bar.offsetHeight + split.clientWidth * 640 / 1600 + form.offsetHeight + pad;
                    panel.toggleAttribute('data-crowded', stacked.matches && needed > window.innerHeight);
                };
                fit();
                window.addEventListener('resize', fit);
                new ResizeObserver(fit).observe(form);
            })();
        </script>
    @else
        <div class="flex min-h-dvh flex-col">
            <div class="flex items-center justify-end gap-3 p-4">
                <livewire:theme-switcher />
                <x-language-switch />
            </div>

            <main class="flex flex-1 items-center justify-center px-4 pb-16">
                {{ $slot }}
            </main>
        </div>
    @endif
</x-layouts.base>
