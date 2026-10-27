<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Reports\Reports;
use App\Support\ListState;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Links that open a list already filtered (CLAUDE.md "List state"): the
 * route names a record, the filters go into the list's session state, and
 * the browser lands on the list's clean URL.
 */
class ListPresetController extends Controller
{
    /**
     * "Stock card" from an item's stock page: the stock-card report for that
     * item in that warehouse.
     */
    public function stockCard(Request $request, Product $product, Warehouse $warehouse): RedirectResponse
    {
        $report = Reports::get('stock-card');
        Gate::authorize($report->permission());

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        ListState::put('reports.'.$report->key(), [
            'filters' => $report->normalize(['customer' => $product->customer_id, 'item' => $product->id, 'warehouse' => $warehouse->id], $user),
        ]);

        return redirect()->route('reports.show', $report->key());
    }

    /**
     * An item from the top bar search: the Stock page showing that item in
     * all of the user's warehouses.
     */
    public function stockItem(Request $request, Product $product): RedirectResponse
    {
        return $this->stockPage($request, ['customer' => $product->customer_id, 'search' => $product->sku]);
    }

    /**
     * A customer from the top bar search: the Stock page showing what that
     * customer has in all of the user's warehouses.
     */
    public function stockCustomer(Request $request, Customer $customer): RedirectResponse
    {
        return $this->stockPage($request, ['customer' => $customer->id]);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function stockPage(Request $request, array $filters): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // The Stock page is the stock-on-hand report under its own list state key (StockPage).
        ListState::put('stock', [
            'filters' => Reports::get('stock-on-hand')->normalize($filters + ['warehouse' => 'all'], $user),
        ]);

        return redirect()->route('stock');
    }

    /**
     * "Incoming" from Home: the list of a dispatch/receive type (Transfer)
     * showing what is on its way to any of the user's warehouses (what Home counts).
     */
    public function incoming(Request $request): RedirectResponse
    {
        // The type is a route default (routes/web.php), not part of the URL.
        $type = TransactionTypes::get((string) $request->route('type'));
        abort_unless($type->dispatchReceive(), 404);

        ListState::put($type->slug(), ['direction' => 'incoming', 'warehouse' => 'all']);

        return redirect()->to($type->route());
    }
}
