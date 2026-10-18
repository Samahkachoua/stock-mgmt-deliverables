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
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --fg: #0f172a; --muted: #475569; --line: #e2e8f0; --brand: #1e3a5f; --on-brand: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1220; --card: #111a2b; --fg: #f1f5f9; --muted: #cbd5e1; --line: #1f2a3d; --brand: #3d6aa3; --on-brand: #ffffff; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--fg); font-family: "IBM Plex Sans Arabic", Inter, system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 32px; text-align: center; }
        .code { font-size: 14px; font-weight: 600; color: var(--muted); letter-spacing: .05em; }
        h1 { margin: 8px 0 0; font-size: 20px; }
        p { margin: 8px 0 0; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .actions { margin-top: 24px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        a, button { display: inline-flex; align-items: center; min-height: 44px; padding: 0 20px; border-radius: 8px; font: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .primary { background: var(--brand); color: var(--on-brand); border: 0; }
        .secondary { background: var(--card); color: var(--fg); border: 1px solid var(--line); }
    </style>
</head>
<body>
    <main>
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
