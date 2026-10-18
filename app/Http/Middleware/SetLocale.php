<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Use the user's preferred_lang, or the guest's session choice, falling back to the app locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = $user instanceof User ? $user->preferred_lang : $request->session()->get('locale');

        if (is_string($locale) && array_key_exists($locale, config('ui.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
