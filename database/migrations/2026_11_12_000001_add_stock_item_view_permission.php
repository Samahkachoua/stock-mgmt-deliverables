<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Opening an item's stock in a warehouse (/stock/{item}/{warehouse}, from a
     * Stock or Stock by expiry row) gets its own permission (it came with
     * stock.view). Every role that sees the Stock page keeps opening it.
     * Same as schema.sql; a database created from it already has it.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'stock.item.view')->exists()) {
            Permissions::flush();

            return;
        }

        $permissionId = DB::table('permission')->insertGetId([
            'code' => 'stock.item.view',
            'description' => "Open an item's stock in a warehouse (from Stock and Stock by expiry)",
            'module' => 'STOCK',
        ], 'id');

        $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
            ->merge(DB::table('role_permission as rp')
                ->join('permission as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.code', 'stock.view')
                ->pluck('rp.role_id'))
            ->unique();

        DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->where('code', 'stock.item.view')->delete();

        Permissions::flush();
    }
};
