<?php

namespace App\Http\Controllers;

use App\Support\Transactions\Documents;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TransactionController extends Controller
{
    /**
     * The read-only details page shared by every transaction type
     * (CLAUDE.md "Screen patterns" 3).
     */
    public function show(Request $request, string $reference): View
    {
        [$ui, $document] = $this->document($request, $reference);

        return view('transactions.show', [
            'ui' => $ui,
            'document' => $document,
        ]);
    }

    /**
     * The same document on A4, in the print layout (CLAUDE.md "Printing"):
     * no navigation or buttons, always light, prints itself on load.
     */
    public function print(Request $request, string $reference): View
    {
        [$ui, $document] = $this->document($request, $reference);

        return view('transactions.print', [
            'ui' => $ui,
            'document' => $document,
        ]);
    }

    /**
     * @return array{0: TypeUi, 1: array<string, mixed>}
     */
    private function document(Request $request, string $reference): array
    {
        // The type is a route default (routes/web.php), not part of the URL.
        $ui = TransactionTypes::get((string) $request->route('type'));
        $document = Documents::find($ui, $reference);

        abort_if($document === null, 404);

        return [$ui, $document];
    }
}
