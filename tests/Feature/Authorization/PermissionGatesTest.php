<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;

it('allows what the user\'s roles grant and nothing else', function () {
    $operator = userWithRole('OPERATOR');

    expect(Gate::forUser($operator)->allows('stock.in.create'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('stock.view'))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('stock.damage.approve'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('stock.opening.create'))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('transaction.cancel'))->toBeFalse();
});

it('combines the permissions of several roles', function () {
    $user = userWithRole('DATA_ENTRY');
    $user->roles()->attach(Role::where('code', 'AUDITOR')->firstOrFail());

    expect(Gate::forUser($user)->allows('product.import'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('audit.view'))->toBeTrue();
});

it('denies everything to a user without roles', function () {
    $user = User::factory()->create();

    expect(collect(Permissions::codes())->filter(fn ($code) => Gate::forUser($user)->allows($code)))->toBeEmpty();
});

it('denies everything to an inactive user', function () {
    $admin = userWithRole('ADMIN');
    $admin->update(['active' => false]);

    expect(Gate::forUser($admin->fresh())->allows('stock.view'))->toBeFalse();
});

it('leaves abilities that are not permission codes to other gates', function () {
    Gate::define('custom.ability', fn () => true);

    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('custom.ability'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('no.such.permission'))->toBeFalse();
});

it('picks up changed grants after the cache is flushed', function () {
    $auditor = userWithRole('AUDITOR');
    expect(Gate::forUser($auditor)->allows('product.manage'))->toBeFalse();

    Role::where('code', 'AUDITOR')->firstOrFail()
        ->permissions()->attach(Permission::where('code', 'product.manage')->firstOrFail());
    Permissions::flush();

    expect(Gate::forUser($auditor->fresh())->allows('product.manage'))->toBeTrue();
});

it('opens a page only with its permission', function (string $role, string $route, int $status) {
    $this->actingAs(userWithRole($role))
        ->get(route($route))
        ->assertStatus($status);
})->with([
    ['OPERATOR', 'receive', 200],
    ['OPERATOR', 'items', 403],
    ['OPERATOR', 'settings', 403],
    ['DATA_ENTRY', 'items', 200],
    ['DATA_ENTRY', 'settings.warehouses', 200],
    ['AUDITOR', 'stock', 200],
    ['AUDITOR', 'items', 403],
    ['AUDITOR', 'settings', 403],
    ['ADMIN', 'settings.users', 200],
]);

it('shows only the sidebar items the user may open', function () {
    $this->actingAs(userWithRole('OPERATOR'))
        ->get(route('receive'))
        ->assertSee(route('stock'))
        ->assertDontSee(route('items'))
        ->assertDontSee(route('settings'));
});

it('explains a refused page in the user\'s language', function () {
    $user = userWithRole('OPERATOR');
    $user->update(['preferred_lang' => 'ar']);

    $this->actingAs($user)
        ->get(route('settings'))
        ->assertForbidden()
        ->assertSee(__('app.forbidden_title', [], 'ar'));
});

it('lets managers and auditors cancel documents', function (string $role, bool $allowed) {
    expect(Gate::forUser(userWithRole($role))->allows('transaction.cancel'))->toBe($allowed);
})->with([
    ['ADMIN', true],
    ['MANAGER', true],
    ['AUDITOR', true],
    ['OPERATOR', false],
    ['DATA_ENTRY', false],
    ['CUSTOMER', false],
]);

it('opens settings for anyone who may manage one of its tabs', function (string $permission) {
    $role = Role::create(['code' => 'TEST_'.strtoupper(str_replace('.', '_', $permission)), 'name' => 'Test']);
    $role->permissions()->attach(Permission::where('code', $permission)->firstOrFail());
    Permissions::flush();

    $user = userWithRole($role->code);

    expect(Gate::forUser($user)->allows('settings.open'))->toBeTrue();
})->with(['masterdata.manage', 'admin.users.manage', 'admin.settings.manage']);
