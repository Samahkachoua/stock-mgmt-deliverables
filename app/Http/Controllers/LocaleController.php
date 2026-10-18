<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Save the chosen language (on the user, or in the session for guests) and reload the page.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('ui.locales')), 404);

        $user = $request->user();

        if ($user instanceof User) {
            $user->update(['preferred_lang' => $locale]);
        }

        // Also kept in the session so the sign-in page stays in this language after signing out.
        $request->session()->put('locale', $locale);

        return back();
    }
}
