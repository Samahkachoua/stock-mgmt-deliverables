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
        ->toContain('stock.opening.create', 'stock.view', 'masterdata.manage', 'transaction.cancel')
        ->toContain('draft.in.create', 'draft.in.confirm', 'draft.out.create', 'draft.out.confirm');
});

it('grants every permission to ADMIN', function () {
    expect(permissionCodesForRole('ADMIN'))
        ->toEqualCanonicalizing(DB::table('permission')->pluck('code')->all());
});

/** Every role that sees stock and documents sees each type's list. */
const LIST_VIEWS = ['stock.in.view', 'stock.out.view', 'stock.transfer.view', 'stock.adjustment.view', 'stock.damage.view', 'stock.opening.view'];

it('grants the default permissions to each role', function (string $role, array $expected) {
    expect(permissionCodesForRole($role))->toEqualCanonicalizing($expected);
})->with([
    'MANAGER' => ['MANAGER', [
        'stock.in.create', 'stock.out.create', 'stock.transfer.dispatch', 'stock.transfer.receive',
        'stock.damage.create', 'stock.damage.approve', 'stock.adjustment.create', 'stock.adjustment.approve',
        'stock.opening.create', 'stock.view', 'report.view', 'masterdata.manage',
        'product.manage', 'product.import', 'transaction.cancel',
        'draft.in.create', 'draft.in.confirm', 'draft.out.create', 'draft.out.confirm',
        'draft.in.view', 'draft.out.view', ...LIST_VIEWS,
    ]],
    'OPERATOR' => ['OPERATOR', [
        'stock.in.create', 'stock.out.create', 'stock.damage.create', 'stock.adjustment.create',
        'stock.transfer.dispatch', 'stock.transfer.receive', 'stock.view',
        'draft.in.create', 'draft.out.create', 'draft.in.view', 'draft.out.view', ...LIST_VIEWS,
    ]],
    'DATA_ENTRY' => ['DATA_ENTRY', ['product.manage', 'product.import', 'masterdata.manage', 'stock.view', ...LIST_VIEWS]],
    'AUDITOR' => ['AUDITOR', ['stock.view', 'report.view', 'audit.view', 'transaction.cancel', ...LIST_VIEWS]],
    'CUSTOMER' => ['CUSTOMER', ['stock.view', 'draft.in.create', 'draft.out.create', 'draft.in.view', 'draft.out.view', ...LIST_VIEWS]],
]);
