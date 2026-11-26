{!! $notification['message'] !!}

@if ($notification['reason'])
{!! __('notifications.reason') !!}: {!! $notification['reason'] !!}

@endif
{!! __('notifications.mail.open') !!}: {!! $notification['url'] !!}

{!! $notification['why'] !!}

--
{!! __('auth.mail.footer', ['app' => config('app.name')]) !!}
