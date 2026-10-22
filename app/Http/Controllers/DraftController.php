<?php

namespace App\Http\Controllers;

use App\Support\Drafts\DraftDocuments;
use App\Support\Drafts\DraftTypes;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DraftController extends Controller
{
    /**
     * A draft on A4, in the print layout (CLAUDE.md "Printing"): no navigation
     * or buttons, always light, prints itself on load.
     */
    public function print(Request $request, string $reference): View
    {
        // The type is a route default (routes/web.php), not part of the URL.
        $kind = DraftTypes::get((string) $request->route('type'));
        $draft = DraftDocuments::find($kind, $reference);

        abort_if($draft === null, 404);

        return view('drafts.print', [
            'kind' => $kind,
            'draft' => $draft,
        ]);
    }
}
