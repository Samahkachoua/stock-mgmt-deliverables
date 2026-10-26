<?php

namespace App\Http\Controllers;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Transactions\PendingApprovals;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * The page after signing in: no charts, just large links to what the user
     * can start, what waits for their approval and what is arriving here.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        // In sidebar order (config/transactions.php), like everywhere else.
        $order = array_keys(config('transactions.types'));
        $types = collect(TransactionTypes::all())->sortBy(fn (TypeUi $type) => array_search($type->code, $order, true));

        $pending = PendingApprovals::bySlug($user);
        $approvals = $types
            ->filter(fn (TypeUi $type) => $type->approvePermission() !== null && $user->hasPermission($type->approvePermission()))
            ->map(fn (TypeUi $type) => ['type' => $type, 'count' => $pending[$type->slug()] ?? 0])
            ->values();

        $arriving = $user->hasPermission('stock.transfer.receive')
            ? StockTransaction::query()
                ->where('transaction_type', 'TRANSFER')
                ->where('status', 'DISPATCHED')
                // On their way to any of the user's warehouses.
                ->whereIn('to_warehouse_id', $user->warehouseIds())
                ->count()
            : null;

        return view('home', [
            'create' => $this->creatable($user, $types),
            'approvals' => $approvals,
            'arriving' => $arriving,
            'transfers' => $types->get('TRANSFER'),
        ]);
    }

    /**
     * What the user may start: draft receipts / orders and transaction types
     * they hold the create permission for, in sidebar order (config('ui.nav'):
     * each draft sits above the document it becomes).
     *
     * @param  Collection<string, TypeUi>  $types
     * @return Collection<int, TypeUi|DraftUi>
     */
    private function creatable(User $user, Collection $types): Collection
    {
        $order = array_column(config('ui.nav'), 'route');

        return collect(DraftTypes::all())->values()
            ->concat($types->values())
            // A Delivery note has no "New" page: it comes from an Order.
            ->filter(fn (TypeUi|DraftUi $kind) => ($kind instanceof DraftUi || $kind->creatable()) && $user->hasPermission($kind->createPermission()))
            ->sortBy(fn (TypeUi|DraftUi $kind) => array_search($kind->slug(), $order, true))
            ->values();
    }
}
