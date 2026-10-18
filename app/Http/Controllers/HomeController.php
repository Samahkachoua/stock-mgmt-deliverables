<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Transactions\PendingApprovals;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Http\Request;
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
        $warehouseId = (string) $request->session()->get(EnsureCurrentWarehouse::SESSION_KEY);
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
                ->where('to_warehouse_id', $warehouseId)
                ->count()
            : null;

        return view('home', [
            'create' => $types->filter(fn (TypeUi $type) => $user->hasPermission($type->createPermission()))->values(),
            'approvals' => $approvals,
            'arriving' => $arriving,
            'transfers' => $types->get('TRANSFER'),
        ]);
    }
}
