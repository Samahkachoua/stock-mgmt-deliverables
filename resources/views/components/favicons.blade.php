{{--
    Favicon, app icons and theme colour for every page's <head> (app, guest, print, errors).
    Bump `v` when the icons change so browsers drop their cached copy.
--}}
@php($v = '?v=2')
<link rel="icon" href="{{ asset('favicon.ico') }}{{ $v }}" sizes="48x48">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}{{ $v }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}{{ $v }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}{{ $v }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}{{ $v }}">
<meta name="theme-color" content="#0074b5">
