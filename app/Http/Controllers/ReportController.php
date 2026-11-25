<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Reports\Reports;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * The Reports page: one link per report the user may open, in sections.
     * Each report's filters and exports live on its own page
     * (App\Livewire\Reports\ReportPage), kept in the session, not the URL.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return view('reports.index', [
            'reports' => Reports::listedFor($user),
            'groups' => Reports::groupedFor($user),
        ]);
    }
}
