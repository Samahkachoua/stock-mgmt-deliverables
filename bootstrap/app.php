<?php

use App\Http\Middleware\EnsureHasWarehouse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\ErrorReference;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->append(SecurityHeaders::class);

        // Behind a load balancer (hosting platform), trust its forwarded IP and HTTPS
        // headers: TRUSTED_PROXIES=* or a comma-separated list. Unset = trust none.
        if (filled(env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : explode(',', (string) env('TRUSTED_PROXIES')));
        }

        // A page left open past the session lifetime still carries the old CSRF token,
        // so signing out from it would show "The page expired". Signing out needs no
        // token: the session cookie is SameSite=lax, so another site can't post it here.
        $middleware->validateCsrfTokens(except: ['logout']);

        $middleware->alias([
            'warehouse' => EnsureHasWarehouse::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unexpected errors are logged under an ERR- reference, which is all the user sees.
        ErrorReference::register($exceptions);
    })->create();
