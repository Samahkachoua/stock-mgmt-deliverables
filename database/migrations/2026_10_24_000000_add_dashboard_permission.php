<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * dashboard.view opens the owner dashboard (/dashboard). Only ADMIN holds
     * it for now; other roles get it from Settings › Roles. Same as schema.sql;
     * a database created from it already has it.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'dashboard.view')->exists()) {
            Permissions::flush();

            return;
        }

        $permissionId = DB::table('permission')->insertGetId(['code' => 'dashboard.view', 'description' => 'View the owner dashboard', 'module' => 'REPORT'], 'id');

        DB::table('role_permission')->insertOrIgnore(DB::table('role')->where('code', 'ADMIN')->pluck('id')
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->all());

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->where('code', 'dashboard.view')->delete();

        Permissions::flush();
    }
};
