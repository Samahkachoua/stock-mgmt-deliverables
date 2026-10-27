<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Settings › Roles section (permission.module) of each permission that moves
     * out of STOCK: one section per document type and draft kind.
     *
     * @var array<string, string>
     */
    private const MODULES = [
        'draft.in.view' => 'DRAFT_IN', 'draft.in.create' => 'DRAFT_IN', 'draft.in.confirm' => 'DRAFT_IN',
        'stock.in.view' => 'IN', 'stock.in.create' => 'IN',
        'draft.out.view' => 'DRAFT_OUT', 'draft.out.create' => 'DRAFT_OUT', 'draft.out.confirm' => 'DRAFT_OUT',
        'stock.out.view' => 'OUT', 'stock.out.create' => 'OUT',
        'stock.transfer.view' => 'TRANSFER', 'stock.transfer.dispatch' => 'TRANSFER', 'stock.transfer.receive' => 'TRANSFER',
        'stock.adjustment.view' => 'ADJUSTMENT', 'stock.adjustment.create' => 'ADJUSTMENT', 'stock.adjustment.approve' => 'ADJUSTMENT',
        'stock.damage.view' => 'DAMAGE', 'stock.damage.create' => 'DAMAGE', 'stock.damage.approve' => 'DAMAGE',
        'stock.opening.view' => 'OPENING_BALANCE', 'stock.opening.create' => 'OPENING_BALANCE',
        'audit.view' => 'REPORT',
    ];

    /**
     * New permission => [description, module, the permission it replaces]:
     * every role holding the old one gets it, then the old one is removed.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SPLIT = [
        'stock.in.cancel' => ['Cancel receipts', 'IN', 'transaction.cancel'],
        'stock.out.cancel' => ['Cancel delivery notes', 'OUT', 'transaction.cancel'],
        'stock.opening.cancel' => ['Cancel opening balances', 'OPENING_BALANCE', 'transaction.cancel'],
        'report.stock_card.view' => ['View the stock card report', 'REPORT', 'report.view'],
        'report.expiry.view' => ['View the expiry report', 'REPORT', 'report.view'],
        'report.dormant_stock.view' => ['View the dormant stock report', 'REPORT', 'report.view'],
        'report.damage.view' => ['View the damage report', 'REPORT', 'report.view'],
        'report.adjustments.view' => ['View the adjustments report', 'REPORT', 'report.view'],
        'report.transfers_in_transit.view' => ['View the transfers in transit report', 'REPORT', 'report.view'],
        'report.transfer_shortages.view' => ['View the transfer shortages report', 'REPORT', 'report.view'],
        'report.delivery_differences.view' => ['View the delivery discrepancies report', 'REPORT', 'report.view'],
    ];

    /** @var array<string, array{0: string, 1: string}> removed permission => [description, module] */
    private const REMOVED = [
        'transaction.cancel' => ['Cancel stock documents', 'STOCK'],
        'report.view' => ['View reports', 'REPORT'],
    ];

    /**
     * Each document type gets its own section in Settings › Roles (and its own
     * Cancel instead of the shared transaction.cancel); each report its own
     * permission instead of report.view. Nobody loses access: the new
     * permissions go to every role that held the one they replace. Same as
     * schema.sql; a database created from it already has them.
     */
    public function up(): void
    {
        if (DB::table('permission')->where('code', 'report.stock_card.view')->exists()) {
            Permissions::flush();

            return;
        }

        foreach (self::SPLIT as $code => [$description, $module, $from]) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $description, 'module' => $module], 'id');

            $roles = DB::table('role')->where('code', 'ADMIN')->pluck('id')
                ->merge($this->holders($from))
                ->unique();

            DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        foreach (self::MODULES as $code => $module) {
            DB::table('permission')->where('code', $code)->update(['module' => $module]);
        }

        DB::table('permission')->where('code', 'stock.view')->update(['description' => 'View stock balances']);

        // Their grants go with them (ON DELETE CASCADE).
        DB::table('permission')->whereIn('code', array_keys(self::REMOVED))->delete();

        Permissions::flush();
    }

    public function down(): void
    {
        foreach (self::REMOVED as $code => [$description, $module]) {
            $permissionId = DB::table('permission')->insertGetId(['code' => $code, 'description' => $description, 'module' => $module], 'id');
            $replacements = array_keys(array_filter(self::SPLIT, fn (array $split) => $split[2] === $code));

            DB::table('role_permission')->insertOrIgnore($this->holders(...$replacements)->unique()
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        DB::table('permission')->whereIn('code', array_keys(self::SPLIT))->delete();

        foreach (self::MODULES as $code => $module) {
            DB::table('permission')->where('code', $code)->update(['module' => $code === 'audit.view' ? 'ADMIN' : 'STOCK']);
        }

        DB::table('permission')->where('code', 'stock.view')->update(['description' => 'View stock balances and documents']);

        Permissions::flush();
    }

    /**
     * Roles holding any of $codes.
     *
     * @return Collection<int, mixed>
     */
    private function holders(string ...$codes): Collection
    {
        return DB::table('role_permission as rp')
            ->join('permission as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.code', $codes)
            ->pluck('rp.role_id');
    }
};
