<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    /**
     * Settings has no page of its own: open the first tab the user may use.
     */
    public function __invoke(): RedirectResponse
    {
        $tab = collect(config('ui.settings_tabs'))->first(fn (array $tab) => Gate::allows($tab['permission']));

        abort_if($tab === null, 403);

        return redirect()->route($tab['route']);
    }
}
