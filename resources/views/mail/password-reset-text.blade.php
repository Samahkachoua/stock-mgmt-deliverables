{!! __('auth.mail.reset.heading') !!}

{!! __('auth.mail.greeting', ['name' => $name]) !!}

{!! __('auth.mail.reset.intro', ['app' => config('app.name')]) !!}

{!! __('auth.mail.reset.button') !!}: {!! $url !!}

{!! __('auth.mail.reset.expires', ['minutes' => $minutes]) !!}

{!! __('auth.mail.reset.ignore') !!}

--
{!! __('auth.mail.footer', ['app' => config('app.name')]) !!}
