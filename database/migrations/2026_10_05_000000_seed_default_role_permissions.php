<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permissions missing from schema.sql's seed data.
     *
     * @var list<array{code: string, description: string, module: string}>
     */
    private const NEW_PERMISSIONS = [
        ['code' => 'stock.opening.create', 'description' => 'Create opening balance documents', 'module' => 'STOCK'],
        ['code' => 'stock.view', 'description' => 'View stock balances and documents', 'module' => 'STOCK'],
        ['code' => 'masterdata.manage', 'description' => 'Manage warehouses, customers and units', 'module' => 'MASTERDATA'],
        ['code' => 'transaction.cancel', 'description' => 'Cancel stock documents', 'module' => 'STOCK'],
    ];

    /**
     * Default grants for the system roles seeded by schema.sql.
     * ADMIN is not listed: it receives every permission.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'MANAGER' => [
            'stock.in.create',
            'stock.out.create',
            'stock.transfer.dispatch',
            'stock.transfer.receive',
            'stock.damage.create',
            'stock.damage.approve',
            'stock.adjustment.create',
            'stock.adjustment.approve',
            'stock.opening.create',
            'stock.view',
            'report.view',
            'masterdata.manage',
            'product.manage',
            'product.import',
            'transaction.cancel',
        ],
        'OPERATOR' => [
            'stock.in.create',
            'stock.out.create',
            'stock.damage.create',
            'stock.adjustment.create',
            'stock.transfer.dispatch',
            'stock.transfer.receive',
            'stock.view',
        ],
        'DATA_ENTRY' => [
            'product.manage',
            'product.import',
            'masterdata.manage',
            'stock.view',
        ],
        'AUDITOR' => [
            'stock.view',
            'report.view',
            'audit.view',
            'transaction.cancel',
        ],
        'CUSTOMER' => [
            'stock.view',
        ],
    ];

    public function up(): void
    {
        // Built from schema.sql: it has these permissions already (and newer
        // ones in place of report.view / transaction.cancel).
        if (DB::table('permission')->where('code', 'stock.view')->exists()) {
            Permissions::flush();

            return;
        }

        DB::table('permission')->insertOrIgnore(self::NEW_PERMISSIONS);

        $roles = $this->roleIds();
        $permissions = DB::table('permission')->pluck('id', 'code');

        $rows = $permissions->map(fn (string $permissionId) => [
            'role_id' => $roles['ADMIN'],
            'permission_id' => $permissionId,
        ])->values()->all();

        foreach (self::GRANTS as $roleCode => $permissionCodes) {
            foreach ($permissionCodes as $permissionCode) {
                $rows[] = [
                    'role_id' => $roles[$roleCode],
                    'permission_id' => $permissions[$permissionCode]
                        ?? throw new RuntimeException("Permission {$permissionCode} not found."),
                ];
            }
        }

        DB::table('role_permission')->insertOrIgnore($rows);

        Permissions::flush();
    }

    public function down(): void
    {
        // Removing the new permissions also removes their grants (ON DELETE CASCADE).
        DB::table('permission')->whereIn('code', array_column(self::NEW_PERMISSIONS, 'code'))->delete();

        $roles = $this->roleIds();
        $permissions = DB::table('permission')->pluck('id', 'code');

        // ADMIN was granted every permission that existed when this ran.
        DB::table('role_permission')->where('role_id', $roles['ADMIN'])->delete();

        foreach (self::GRANTS as $roleCode => $permissionCodes) {
            DB::table('role_permission')
                ->where('role_id', $roles[$roleCode])
                ->whereIn('permission_id', $permissions->only($permissionCodes)->values())
                ->delete();
        }

        Permissions::flush();
    }

    /**
     * @return array<string, string> role code => id
     */
    private function roleIds(): array
    {
        $codes = ['ADMIN', ...array_keys(self::GRANTS)];
        $roles = DB::table('role')->whereIn('code', $codes)->pluck('id', 'code')->all();

        foreach ($codes as $code) {
            if (! isset($roles[$code])) {
                throw new RuntimeException("Role {$code} not found.");
            }
        }

        return $roles;
    }
};
