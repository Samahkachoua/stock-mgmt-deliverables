{{-- Emails are always light (like printouts and exports); mail clients ignore the app's CSS variables. --}}
@php($c = \App\Support\Palette::light())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.mail.changed.subject', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:{{ $c["surface"] }};color:{{ $c["fg"] }};font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:1.5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:{{ $c["card"] }};border:1px solid {{ $c["border"] }};border-radius:12px;">
        <tr>
            <td style="padding:24px;text-align:start;">
                @include('mail.partials.logo')
                <h1 style="margin:0 0 16px;font-size:20px;">{{ __('auth.mail.changed.heading') }}</h1>
                <p style="margin:0 0 12px;">{{ __('auth.mail.greeting', ['name' => $name]) }}</p>
                <p style="margin:0 0 16px;">{{ __('auth.mail.changed.intro', ['app' => config('app.name'), 'date' => $when]) }}</p>
                <p style="margin:0;padding:12px;border-radius:8px;background:{{ $c["status-pending-soft"] }};border:1px solid {{ $c["status-pending-line"] }};color:{{ $c["fg"] }};font-weight:600;">{{ __('auth.mail.changed.not_you') }}</p>
            </td>
        </tr>
        @include('mail.partials.footer')
    </table>
</body>
</html>
