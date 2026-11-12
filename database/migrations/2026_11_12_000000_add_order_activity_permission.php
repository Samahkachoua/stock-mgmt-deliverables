<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeing an Order's activity (created, edited, confirmed...) gets its own
     * permission (it came with viewing the order). Every role that sees orders
     * keeps seeing it. Same as schema.sql; a database created from it already has it.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'draft.out.activity')->exists()) {
            Permissions::flush();

            return;
        }

        $permissionId = DB::table('permission')->insertGetId([
            'code' => 'draft.out.activity',
            'description' => 'See the activity of an order (created, edited, confirmed...)',
            'module' => 'DRAFT_OUT',
        ], 'id');

        $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
            ->merge(DB::table('role_permission as rp')
                ->join('permission as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.code', 'draft.out.view')
                ->pluck('rp.role_id'))
            ->unique();

        DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->where('code', 'draft.out.activity')->delete();

        Permissions::flush();
    }
};
