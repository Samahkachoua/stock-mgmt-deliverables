{{-- Emails are always light (like printouts and exports); mail clients ignore the app's CSS variables. --}}
@php($c = \App\Support\Palette::light())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.mail.reset.subject', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:{{ $c["surface"] }};color:{{ $c["fg"] }};font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:1.5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:{{ $c["card"] }};border:1px solid {{ $c["border"] }};border-radius:12px;">
        <tr>
            <td style="padding:24px;text-align:start;">
                @include('mail.partials.logo')
                <h1 style="margin:0 0 16px;font-size:20px;">{{ __('auth.mail.reset.heading') }}</h1>
                <p style="margin:0 0 12px;">{{ __('auth.mail.greeting', ['name' => $name]) }}</p>
                <p style="margin:0 0 20px;">{{ __('auth.mail.reset.intro', ['app' => config('app.name')]) }}</p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                    <tr>
                        <td style="border-radius:8px;background:{{ $c["brand"] }};">
                            <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;color:{{ $c["on-brand"] }};font-weight:600;text-decoration:none;border-radius:8px;">{{ __('auth.mail.reset.button') }}</a>
                        </td>
                    </tr>
                </table>

                <p style="margin:0 0 16px;font-weight:600;">{{ __('auth.mail.reset.expires', ['minutes' => $minutes]) }}</p>
                <p style="margin:0 0 4px;color:{{ $c["muted"] }};font-size:13px;">{{ __('auth.mail.reset.fallback') }}</p>
                <p dir="ltr" style="margin:0 0 20px;font-size:13px;word-break:break-all;text-align:left;"><a href="{{ $url }}" style="color:{{ $c["brand"] }};">{{ $url }}</a></p>
                <p style="margin:0;">{{ __('auth.mail.reset.ignore') }}</p>
            </td>
        </tr>
        @include('mail.partials.footer')
    </table>
</body>
</html>
