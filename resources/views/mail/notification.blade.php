{{-- Emails are always light (like printouts and exports); mail clients ignore the app's CSS variables. --}}
@php($c = \App\Support\Palette::light())
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $notification['message'] }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:{{ $c["surface"] }};color:{{ $c["fg"] }};font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:1.5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:{{ $c["card"] }};border:1px solid {{ $c["border"] }};border-radius:12px;">
        <tr>
            <td style="padding:24px;text-align:start;">
                @include('mail.partials.logo')
                <h1 style="margin:0 0 16px;font-size:20px;">{{ $notification['message'] }}</h1>
                @if ($notification['reason'])
                    <p style="margin:0 0 16px;"><strong>{{ __('notifications.reason') }}:</strong> {{ $notification['reason'] }}</p>
                @endif
                <a href="{{ $notification['url'] }}" style="display:inline-block;padding:10px 20px;background:{{ $c["brand"] }};color:{{ $c["on-brand"] }};text-decoration:none;border-radius:8px;font-weight:600;">{{ __('notifications.mail.open') }}</a>
                <p style="margin:20px 0 0;color:{{ $c["muted"] }};font-size:13px;">{{ $notification['why'] }}</p>
            </td>
        </tr>
        @include('mail.partials.footer')
    </table>
</body>
</html>
