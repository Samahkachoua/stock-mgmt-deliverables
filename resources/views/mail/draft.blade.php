{{-- Emails are always light (like printouts and exports); mail clients ignore the app's CSS variables. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $draft['subject'] }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:#f4f5f7;color:#111827;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:1.5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #d1d5db;border-radius:8px;">
        <tr>
            <td style="padding:24px;text-align:start;">
                <p style="margin:0 0 4px;color:#4b5563;font-size:13px;">{{ config('app.name') }}</p>
                <h1 style="margin:0 0 16px;font-size:20px;">{{ $draft['subject'] }}</h1>
                <p style="margin:0 0 16px;">{{ $draft['intro'] }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-collapse:collapse;">
                    @foreach ([
                        __('drafts.mail.reason') => $draft['reason'],
                        __('drafts.mail.customer') => $draft['customer'],
                        $draft['branch_label'] => $draft['branch'],
                        __('drafts.mail.warehouse') => $draft['warehouse'],
                        __('drafts.mail.date') => $draft['date'],
                        __('drafts.mail.items') => $draft['summary'],
                        __('drafts.mail.note') => $draft['note'],
                    ] as $label => $value)
                        @if (filled($value))
                            <tr>
                                <td style="padding:6px 0;border-top:1px solid #e5e7eb;color:#4b5563;width:40%;vertical-align:top;text-align:start;">{{ $label }}</td>
                                <td style="padding:6px 0;border-top:1px solid #e5e7eb;font-weight:600;text-align:start;">{{ $value }}</td>
                            </tr>
                        @endif
                    @endforeach
                </table>

                <a href="{{ $draft['url'] }}" style="display:inline-block;padding:10px 20px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">{{ $draft['open'] }}</a>

                <p style="margin:20px 0 0;color:#4b5563;font-size:13px;">{{ $draft['why'] }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
