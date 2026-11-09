<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stock by expiry (the Stock page per lot, in the sidebar) gets its own
     * report permission. ADMIN and every role that sees the Stock page get it.
     * Same as schema.sql; a database created from it already has it.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'report.stock_by_expiry.view')->exists()) {
            Permissions::flush();

            return;
        }

        $permissionId = DB::table('permission')->insertGetId(
            ['code' => 'report.stock_by_expiry.view', 'description' => 'View stock by expiry', 'module' => 'REPORT'],
            'id',
        );

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
        DB::table('permission')->where('code', 'report.stock_by_expiry.view')->delete();

        Permissions::flush();
    }
};
