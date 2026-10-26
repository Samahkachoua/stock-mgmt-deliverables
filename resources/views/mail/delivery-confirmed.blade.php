{{-- Emails are always light (like printouts and exports); mail clients ignore the app's CSS variables. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $note['subject'] }}</title>
</head>
<body style="margin:0;padding:24px 16px;background:#f4f5f7;color:#111827;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;line-height:1.5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d1d5db;border-radius:8px;">
        <tr>
            <td style="padding:24px;text-align:start;">
                <p style="margin:0 0 4px;color:#4b5563;font-size:13px;">{{ config('app.name') }}</p>
                <h1 style="margin:0 0 16px;font-size:20px;">{{ $note['subject'] }}</h1>
                <p style="margin:0 0 16px;">{{ $note['intro'] }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-collapse:collapse;">
                    @foreach ($note['facts'] as $label => $value)
                        <tr>
                            <td style="padding:6px 0;border-top:1px solid #e5e7eb;color:#4b5563;width:40%;vertical-align:top;text-align:start;">{{ $label }}</td>
                            <td style="padding:6px 0;border-top:1px solid #e5e7eb;font-weight:600;text-align:start;">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="background:#f3f4f6;">
                            <th scope="col" style="padding:8px;border:1px solid #e5e7eb;text-align:start;">{{ __('deliveries.mail.item') }}</th>
                            <th scope="col" style="padding:8px;border:1px solid #e5e7eb;text-align:end;">{{ __('deliveries.mail.ordered') }}</th>
                            <th scope="col" style="padding:8px;border:1px solid #e5e7eb;text-align:end;">{{ __('deliveries.mail.delivered') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($note['lines'] as $line)
                            <tr>
                                <td style="padding:8px;border:1px solid #e5e7eb;text-align:start;vertical-align:top;">
                                    <strong>{{ $line['item'] }}</strong> <span dir="ltr" style="color:#4b5563;">{{ $line['code'] }}</span>
                                    @if ($line['lot'] !== '')
                                        <br><span style="color:#4b5563;font-size:13px;">{{ $line['lot'] }}</span>
                                    @endif
                                    @if ($line['note'] !== '')
                                        <br><span style="color:#b45309;font-size:13px;font-weight:600;">{{ $line['note'] }}</span>
                                    @endif
                                </td>
                                <td style="padding:8px;border:1px solid #e5e7eb;text-align:end;vertical-align:top;white-space:nowrap;">{{ $line['ordered'] }}</td>
                                <td style="padding:8px;border:1px solid #e5e7eb;text-align:end;vertical-align:top;white-space:nowrap;font-weight:600;">{{ $line['delivered'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p style="margin:0;color:#4b5563;font-size:13px;">{{ $note['why'] }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
