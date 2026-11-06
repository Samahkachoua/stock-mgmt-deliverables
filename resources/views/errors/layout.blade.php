{{--
    Error pages (404, 419, 429, 500, 503). Self-contained on purpose: no Livewire,
    no database, no Vite build, so they still show when the app itself is failing.
    Light or dark follows the device; Arabic is right to left.

    The language: the user's, when the app got far enough to set it; otherwise
    (an unknown address is answered before the app's middleware runs) the
    browser's Accept-Language.
--}}
@php
    $locales = array_keys(config('ui.locales', ['en' => 'English']));
    $locale = request()->route() === null && request()->headers->has('Accept-Language')
        ? (request()->getPreferredLanguage($locales) ?? app()->getLocale())
        : app()->getLocale();
    app()->setLocale($locale);
    $rtl = $locale === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __("app.errors.$key.title") }} · {{ config('app.name') }}</title>
    <x-favicons />
    <style>
        /* Same values as resources/css/app.css (this page loads no build). */
        :root { --bg: #f7f9fb; --card: #ffffff; --fg: #1f2933; --muted: #5f6e84; --line: #e5e9ef; --brand: #0074b5; --brand-hover: #00669f; --on-brand: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --card: #131c2e; --fg: #e2e8f0; --muted: #94a3b8; --line: #26324a; --brand: #0679b9; --brand-hover: #00669f; --on-brand: #ffffff; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--fg); font-family: "IBM Plex Sans Arabic", Inter, system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 32px; text-align: center; }
        .logo { display: block; width: 160px; height: auto; margin: 0 auto 20px; }
        .code { font-size: 14px; font-weight: 600; color: var(--muted); letter-spacing: .05em; }
        h1 { margin: 8px 0 0; font-size: 20px; }
        p { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .actions { margin-top: 24px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a, button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 8px; font: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; transition: background-color 150ms, border-color 150ms; }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .primary { background: var(--brand); color: var(--on-brand); border: 0; }
        .primary:hover { background: var(--brand-hover); }
        .secondary { background: var(--card); color: var(--fg); border: 1px solid var(--line); }
        .secondary:hover { border-color: var(--muted); }
    </style>
</head>
<body>
    <main>
        <img class="logo" src="{{ asset('images/brand/etl-logo.png') }}" srcset="{{ asset('images/brand/etl-logo@2x.png') }} 2x" width="240" height="95" alt="{{ __('app.logo_alt') }}">
        <div class="code" dir="ltr">{{ $code }}</div>
        <h1>{{ __("app.errors.$key.title") }}</h1>
        <p>{{ __("app.errors.$key.body") }}</p>
        @isset($reference)
            <p><bdi>{{ __('app.errors.reference', ['reference' => $reference]) }}</bdi></p>
        @endisset
        <div class="actions">
            <a class="primary" href="{{ url('/') }}">{{ __('app.errors.home') }}</a>
            @if ($code !== 503)
                <button type="button" class="secondary" onclick="history.back()">{{ __('app.errors.back') }}</button>
            @endif
        </div>
    </main>
</body>
</html>
