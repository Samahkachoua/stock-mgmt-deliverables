{{-- The reference is the one the error was logged under (App\Support\ErrorReference). --}}
@include('errors.layout', ['code' => 500, 'key' => 'server', 'reference' => isset($exception) ? \App\Support\ErrorReference::for($exception) : null])
