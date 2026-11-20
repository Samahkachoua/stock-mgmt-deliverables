{!! __('auth.mail.changed.heading') !!}

{!! __('auth.mail.greeting', ['name' => $name]) !!}

{!! __('auth.mail.changed.intro', ['app' => config('app.name'), 'date' => $when]) !!}

{!! __('auth.mail.changed.not_you') !!}

--
{!! __('auth.mail.footer', ['app' => config('app.name')]) !!}
