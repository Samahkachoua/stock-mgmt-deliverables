<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> code => description */
    private const PERMISSIONS = [
        'report.order_lines.view' => 'View the order details report',
        'report.delivery_note_lines.view' => 'View the delivery note details report',
        'report.draft_receipt_lines.view' => 'View the draft receipt details report',
        'report.receipt_lines.view' => 'View the receipt details report',
        'report.opening_balance_lines.view' => 'View the opening balance details report',
    ];

    /**
     * The operation detail reports (Order, Delivery note, Draft receipt,
     * Receipt, Opening balance details), each with its own permission. They go
     * to ADMIN and to every role that sees the other reports (holds the
     * delivery discrepancies report). Same as schema.sql; a database created
     * from it already has them.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'report.order_lines.view')->exists()) {
            Permissions::flush();

            return;
        }

        $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
            ->merge(DB::table('role_permission as rp')
                ->join('permission as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.code', 'report.delivery_differences.view')
                ->pluck('rp.role_id'))
            ->unique();

        foreach (self::PERMISSIONS as $code => $description) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $description, 'module' => 'REPORT'], 'id');

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
