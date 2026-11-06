@props(['title' => null])

@php($theme = \App\Support\Theme::current())
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"
    data-theme="{{ $theme }}"
    @class(['dark' => $theme === 'dark'])
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>
    <x-favicons />

    {{-- Runs before the stylesheet so "system" never flashes the wrong theme. --}}
    <script>
        (function () {
            var root = document.documentElement;
            var query = window.matchMedia('(prefers-color-scheme: dark)');
            window.applyTheme = function (theme) {
                root.dataset.theme = theme;
                root.classList.toggle('dark', theme === 'dark' || (theme === 'system' && query.matches));
            };
            window.applyTheme(root.dataset.theme);
            query.addEventListener('change', function () {
                if (root.dataset.theme === 'system') window.applyTheme('system');
            });
            // Desktop sidebar: open unless this browser closed it (the app layout's toggle).
            // wire:navigate copies the new page's <html> attributes, so it is set again on every swap.
            var applySidebar = function () {
                try {
                    root.classList.toggle('sidebar-collapsed', localStorage.getItem('sidebar') === 'collapsed');
                } catch (e) {}
            };
            applySidebar();
            document.addEventListener('livewire:navigating', function (event) {
                event.detail.onSwap(applySidebar);
            });
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-surface font-sans text-fg antialiased">
    {{ $slot }}

    <x-toasts />

    @livewireScripts
</body>
</html>
