{{-- Embedded when sent (shows without loading remote images); a plain URL when only rendered. --}}
@php($logoPath = public_path('images/brand/etl-logo-email.png'))
<img src="{{ isset($message) ? $message->embed($logoPath) : asset('images/brand/etl-logo-email.png') }}" width="160" alt="{{ __('app.logo_alt') }}" style="display:block;width:160px;height:auto;border:0;margin:0 0 16px;">
