<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single place that decides which warehouse a user is working in
 * (CLAUDE.md: warehouse scoping). Only warehouses assigned through
 * user_warehouse are ever selectable.
 */
class EnsureCurrentWarehouse
{
    public const SESSION_KEY = 'warehouse_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $warehouses = $user->assignedWarehouses();

        if ($warehouses->isEmpty()) {
            return response()->view('errors.no-warehouse', status: 403);
        }

        $current = $warehouses->firstWhere('id', $request->session()->get(self::SESSION_KEY))
            ?? $warehouses->first();

        $request->session()->put(self::SESSION_KEY, $current->id);
        $request->attributes->set('warehouse', $current);

        View::share('currentWarehouse', $current);

        return $next($request);
    }
}
