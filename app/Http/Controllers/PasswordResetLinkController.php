<?php

namespace App\Http\Controllers;

use App\Support\Auth\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The link in the reset email. Moves its token and email into the session and
 * sends the browser on to /reset-password, so the token leaves the address
 * bar, the history and any Referer header after this first request.
 */
class PasswordResetLinkController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $request->session()->put(PasswordReset::SESSION_KEY, [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);

        return redirect()->route('password.reset.form');
    }
}
