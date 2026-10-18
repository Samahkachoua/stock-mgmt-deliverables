<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;

/**
 * A user with a single custom role holding only the given permission (plus stock.view).
 */
function userWithOnlyPermission(string $permission): User
{
    $role = Role::create(['code' => 'ONLY_'.strtoupper(str_replace('.', '_', $permission)), 'name' => 'Test']);
    $role->permissions()->attach(Permission::whereIn('code', [$permission, 'stock.view'])->pluck('id'));
    Permissions::flush();

    return userWithRole($role->code);
}

it('opens the first settings tab the user may use', function (string $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('settings'))
        ->assertRedirect(route('settings.warehouses'));
})->with(['ADMIN', 'DATA_ENTRY']);

it('skips tabs the user may not open', function () {
    $this->actingAs(userWithOnlyPermission('admin.users.manage'))
        ->get(route('settings'))
        ->assertRedirect(route('settings.users'));
});

it('refuses settings to users without any settings permission', function (string $role) {
    $this->actingAs(userWithRole($role))->get(route('settings'))->assertForbidden();
})->with(['OPERATOR', 'AUDITOR', 'CUSTOMER']);

it('protects each tab with its own permission', function (string $tab, string $allowed, string $refused) {
    $this->actingAs(userWithOnlyPermission($allowed))->get(route($tab))->assertOk();
    $this->actingAs(userWithOnlyPermission($refused))->get(route($tab))->assertForbidden();
})->with([
    ['settings.warehouses', 'masterdata.manage', 'admin.users.manage'],
    ['settings.approval-limits', 'admin.settings.manage', 'masterdata.manage'],
    ['settings.users', 'admin.users.manage', 'admin.settings.manage'],
]);

it('shows only the tabs the user may open', function () {
    $this->actingAs(userWithRole('DATA_ENTRY'))
        ->get(route('settings.warehouses'))
        ->assertOk()
        ->assertSee(route('settings.warehouses'))
        ->assertDontSee(route('settings.approval-limits'))
        ->assertDontSee(route('settings.users'));

    $this->actingAs(userWithRole('ADMIN'))
        ->get(route('settings.warehouses'))
        ->assertSee(route('settings.approval-limits'))
        ->assertSee(route('settings.users'));
});

it('renders every tab in Arabic, right to left', function (string $tab) {
    $admin = userWithRole('ADMIN');
    $admin->update(['preferred_lang' => 'ar']);

    $this->actingAs($admin)
        ->get(route($tab))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('settings.title', [], 'ar'));
})->with(['settings.warehouses', 'settings.approval-limits', 'settings.users']);
