<?php

namespace App\Http\Controllers;

use App\Support\Navigation\Sidebar;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * Placeholder for sidebar pages that are not built yet.
     */
    public function __invoke(Request $request): View
    {
        $item = collect(Sidebar::flatItems())->firstWhere('route', $request->route()?->getName());

        abort_if($item === null, 404);

        return view('pages.placeholder', ['item' => $item]);
    }
}
