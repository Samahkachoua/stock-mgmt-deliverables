<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> code => [description, module, the permission whose holders get it] */
    private const PERMISSIONS = [
        'draft.in.cancel' => ['Cancel draft receipts', 'DRAFT_IN', 'draft.in.create'],
        'draft.out.cancel' => ['Cancel orders', 'DRAFT_OUT', 'draft.out.create'],
    ];

    /**
     * Cancelling a Draft receipt / an Order gets its own permission (it was
     * part of creating and editing them). Every role that may create them keeps
     * cancelling them. Same as schema.sql; a database created from it already has them.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'draft.out.cancel')->exists()) {
            Permissions::flush();

            return;
        }

        foreach (self::PERMISSIONS as $code => [$description, $module, $from]) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $description, 'module' => $module], 'id');

            $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
                ->merge(DB::table('role_permission as rp')
                    ->join('permission as p', 'p.id', '=', 'rp.permission_id')
                    ->where('p.code', $from)
                    ->pluck('rp.role_id'))
                ->unique();

            DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->whereIn('code', array_keys(self::PERMISSIONS))->delete();

        Permissions::flush();
    }
};
