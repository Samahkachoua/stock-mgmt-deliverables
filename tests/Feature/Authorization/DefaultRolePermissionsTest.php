<?php

use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function permissionCodesForRole(string $roleCode): array
{
    return DB::table('role_permission')
        ->join('role', 'role.id', '=', 'role_permission.role_id')
        ->join('permission', 'permission.id', '=', 'role_permission.permission_id')
        ->where('role.code', $roleCode)
        ->pluck('permission.code')
        ->all();
}

it('adds the permissions missing from the base schema', function () {
    expect(DB::table('permission')->pluck('code')->all())
        ->toContain('stock.opening.create', 'stock.view', 'masterdata.manage', 'transaction.cancel');
});

it('grants every permission to ADMIN', function () {
    expect(permissionCodesForRole('ADMIN'))
        ->toEqualCanonicalizing(DB::table('permission')->pluck('code')->all());
});

it('grants the default permissions to each role', function (string $role, array $expected) {
    expect(permissionCodesForRole($role))->toEqualCanonicalizing($expected);
})->with([
    'MANAGER' => ['MANAGER', [
        'stock.in.create', 'stock.out.create', 'stock.transfer.dispatch', 'stock.transfer.receive',
        'stock.damage.create', 'stock.damage.approve', 'stock.adjustment.create', 'stock.adjustment.approve',
        'stock.opening.create', 'stock.view', 'report.view', 'masterdata.manage',
        'product.manage', 'product.import', 'transaction.cancel',
    ]],
    'OPERATOR' => ['OPERATOR', [
        'stock.in.create', 'stock.out.create', 'stock.damage.create', 'stock.adjustment.create',
        'stock.transfer.dispatch', 'stock.transfer.receive', 'stock.view',
    ]],
    'DATA_ENTRY' => ['DATA_ENTRY', ['product.manage', 'product.import', 'masterdata.manage', 'stock.view']],
    'AUDITOR' => ['AUDITOR', ['stock.view', 'report.view', 'audit.view', 'transaction.cancel']],
    'CUSTOMER' => ['CUSTOMER', ['stock.view']],
]);
