<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Notify;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A password typed by an administrator (Settings › Users) is temporary: until
 * the user has chosen their own (Account › Change password, or a reset from
 * an emailed link), every other page leads to Change password.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs('account.password')) {
            Notify::warning(__('auth.forced_change'));

            return redirect()->route('account.password');
        }

        return $next($request);
    }
}
