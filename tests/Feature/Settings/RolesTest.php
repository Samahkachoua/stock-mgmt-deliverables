<?php

use App\Livewire\Settings\Roles;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function permissionId(string $code): string
{
    return Permission::where('code', $code)->value('id');
}

function role(string $code): Role
{
    return Role::where('code', $code)->firstOrFail();
}

it('shows every role and permission grouped by module', function () {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->assertSee(__('permissions.modules.STOCK'))
        ->assertSee(__('permissions.labels.stock_in_create'))
        ->assertSee(__('roles.OPERATOR'))
        ->assertSet('grants.'.role('CUSTOMER')->id, fn (array $ids) => collect($ids)->sort()->values()->all()
            === collect(['draft.in.create', 'draft.in.view', 'draft.out.create', 'draft.out.view', 'stock.adjustment.view', 'stock.damage.view', 'stock.in.view', 'stock.opening.view', 'stock.out.view', 'stock.transfer.view', 'stock.view'])->map(fn (string $code) => permissionId($code))->sort()->values()->all());
});

it('saves the matrix and the change applies at once', function () {
    $operator = userWithRole('OPERATOR');
    expect(Gate::forUser($operator)->allows('report.view'))->toBeFalse();

    $grants = role('OPERATOR')->permissions()->pluck('permission.id')->push(permissionId('report.view'))->all();

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->set('grants.'.role('OPERATOR')->id, $grants)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', toast: ['type' => 'success', 'message' => __('settings.roles.saved'), 'url' => null, 'link' => null]);

    // The cached permission map was cleared: no manual flush needed.
    expect(Gate::forUser(User::find($operator->id))->allows('report.view'))->toBeTrue();
});

it('records each changed role in the audit log', function () {
    $admin = userWithRole('ADMIN');

    Livewire::actingAs($admin)
        ->test(Roles::class)
        ->set('grants.'.role('CUSTOMER')->id, [permissionId('stock.view'), permissionId('report.view')])
        ->call('save');

    $rows = DB::table('audit_log')->where('entity', 'role')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->entity_id)->toBe(role('CUSTOMER')->id)
        ->and($rows[0]->action)->toBe('UPDATE')
        ->and($rows[0]->user_id)->toBe($admin->id)
        ->and(json_decode($rows[0]->before_data, true)['permissions'])->toBe(['draft.in.create', 'draft.in.view', 'draft.out.create', 'draft.out.view', 'stock.adjustment.view', 'stock.damage.view', 'stock.in.view', 'stock.opening.view', 'stock.out.view', 'stock.transfer.view', 'stock.view'])
        ->and(json_decode($rows[0]->after_data, true)['permissions'])->toBe(['report.view', 'stock.view']);
});

it('does not let an administrator remove their own user management', function () {
    $without = role('ADMIN')->permissions()->where('code', '!=', 'admin.users.manage')->pluck('permission.id')->all();

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->set('grants.'.role('ADMIN')->id, $without)
        ->call('save')
        ->assertHasErrors('grants')
        ->assertSee(__('settings.roles.errors.keep_own_admin'));

    expect(role('ADMIN')->permissions()->where('code', 'admin.users.manage')->exists())->toBeTrue()
        ->and(DB::table('audit_log')->count())->toBe(0);
});

it('adds a role, records it, and deletes it while nobody has it', function () {
    $component = Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->call('add')
        ->set('newName', 'Shift lead')
        ->set('newCode', 'shift_lead')
        ->call('create')
        ->assertHasNoErrors();

    $role = role('SHIFT_LEAD');
    expect($role->is_system)->toBeFalse();

    $component->call('askDelete', $role->id)->assertSet('confirm.action', 'delete')->call('runConfirmed')->assertHasNoErrors();

    expect(Role::where('code', 'SHIFT_LEAD')->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('entity_id', $role->id)->pluck('action')->all())->toBe(['CREATE', 'DELETE']);
});

it('refuses to delete a built-in role or one that people still have', function () {
    $custom = Role::create(['code' => 'TEMP', 'name' => 'Temp']);
    userWithRole('TEMP');

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->call('askDelete', role('AUDITOR')->id)
        ->assertHasErrors('delete.'.role('AUDITOR')->id)
        ->assertSet('confirm', null)
        ->call('askDelete', $custom->id)
        ->assertHasErrors('delete.'.$custom->id)
        ->assertSet('confirm', null)
        ->assertSee(trans_choice('confirm.blocked.role_in_use', 1, ['name' => 'Temp', 'count' => '1']));

    expect(Role::whereIn('code', ['AUDITOR', 'TEMP'])->count())->toBe(2);
});

it('rejects badly formed or duplicate role codes', function (string $code, string $rule) {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Roles::class)
        ->call('add')
        ->set('newCode', $code)
        ->assertHasErrors(['newCode' => $rule]);
})->with([
    ['operator', 'unique'],
    ['1ST', 'regex'],
    ['has space', 'regex'],
]);

it('is only for users who manage users and roles', function () {
    Livewire::actingAs(userWithRole('MANAGER'))
        ->test(Roles::class)
        ->assertForbidden();
});
