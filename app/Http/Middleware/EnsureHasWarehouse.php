<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in user works only in warehouses assigned to them (user_warehouse,
 * CLAUDE.md: warehouse scoping); one with none gets a page that says so.
 *
 * There is no "current warehouse": each page that needs one has its own
 * field or filter, starting on the user's default (User::defaultWarehouseId()).
 */
class EnsureHasWarehouse
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->warehouseIds() === []) {
            return response()->view('errors.no-warehouse', status: 403);
        }

        return $next($request);
    }
}
