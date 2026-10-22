<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each type's list, details and print pages => what used to open them,
     * which keeps today's access: roles that see stock and documents (stock.view)
     * keep every transaction list, roles with a draft permission keep that
     * kind's draft list.
     *
     * @var array<string, array{description: string, from: list<string>}>
     */
    private const VIEWS = [
        'stock.in.view' => ['description' => 'View receipts', 'from' => ['stock.view']],
        'stock.out.view' => ['description' => 'View issues', 'from' => ['stock.view']],
        'stock.transfer.view' => ['description' => 'View transfers', 'from' => ['stock.view']],
        'stock.adjustment.view' => ['description' => 'View adjustments', 'from' => ['stock.view']],
        'stock.damage.view' => ['description' => 'View damage documents', 'from' => ['stock.view']],
        'stock.opening.view' => ['description' => 'View opening balances', 'from' => ['stock.view']],
        'draft.in.view' => ['description' => 'View draft receipts', 'from' => ['draft.in.create', 'draft.in.confirm']],
        'draft.out.view' => ['description' => 'View draft issues', 'from' => ['draft.out.create', 'draft.out.confirm']],
    ];

    /**
     * A view permission per list (stock.in.view, draft.out.view...). Creating
     * or approving a type opens its pages too (gates in AppServiceProvider).
     * Same as schema.sql; a database created from it already has them.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'stock.in.view')->exists()) {
            Permissions::flush();

            return;
        }

        foreach (self::VIEWS as $code => $view) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $view['description'], 'module' => 'STOCK'], 'id');

            // ADMIN, and every role that could open these pages before.
            $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
                ->merge(DB::table('role_permission as rp')
                    ->join('permission as p', 'p.id', '=', 'rp.permission_id')
                    ->whereIn('p.code', $view['from'])
                    ->pluck('rp.role_id'))
                ->unique();

            DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->whereIn('code', array_keys(self::VIEWS))->delete();

        Permissions::flush();
    }
};
