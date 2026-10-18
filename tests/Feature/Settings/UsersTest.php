<?php

use App\Livewire\Settings\Users;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function roleId(string $code): string
{
    return Role::where('code', $code)->value('id');
}

it('adds a user with roles and warehouses', function () {
    $warehouse = Warehouse::factory()->create();

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('add')
        ->set('full_name', 'Rami Khoury')
        ->set('email', ' rami@example.test ')
        ->set('password', 'secret-pass')
        ->set('roleIds', [roleId('OPERATOR')])
        ->set('warehouseIds', [$warehouse->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Rami Khoury');

    $user = User::where('email', 'rami@example.test')->firstOrFail();

    expect(Hash::check('secret-pass', $user->password_hash))->toBeTrue()
        ->and($user->roles->pluck('code')->all())->toBe(['OPERATOR'])
        ->and($user->warehouses->pluck('id')->all())->toBe([$warehouse->id])
        ->and($user->hasPermission('stock.in.create'))->toBeTrue();
});

it('keeps the password when it is left empty', function () {
    $user = userWithRole('OPERATOR');
    $hash = $user->fresh()->password_hash;

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->set('full_name', 'Renamed')
        ->set('roleIds', [roleId('AUDITOR')])
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();

    expect($user->full_name)->toBe('Renamed')
        ->and($user->password_hash)->toBe($hash)
        ->and($user->roles->pluck('code')->all())->toBe(['AUDITOR']);
});

it('explains what is missing', function () {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('add')
        ->call('save')
        ->assertHasErrors([
            'full_name' => 'required',
            'email' => 'required',
            'password' => 'required',
            'roleIds' => 'required',
            'warehouseIds' => 'required',
        ])
        ->assertSee(__('settings.users.errors.role_required'));
});

it('refuses an email that is already used, whatever its case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('add')
        ->set('email', 'TAKEN@example.test')
        ->assertHasErrors('email')
        ->assertSee(__('settings.users.errors.email_taken'));
});

it('does not let an administrator lock themselves out', function () {
    $admin = userWithRole('ADMIN');

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('edit', $admin->id)
        ->set('roleIds', [roleId('MANAGER')])
        ->set('active', false)
        ->call('save')
        ->assertHasErrors(['roleIds', 'active'])
        ->assertSee(__('settings.users.errors.keep_own_admin'))
        ->assertSee(__('settings.users.errors.keep_own_active'));

    $admin = $admin->fresh();

    expect($admin->active)->toBeTrue()
        ->and($admin->roles->pluck('code')->all())->toBe(['ADMIN']);
});

it('lets an administrator keep their access through another role', function () {
    $admin = userWithRole('ADMIN');
    $backup = Role::create(['code' => 'USER_ADMIN', 'name' => 'User admin']);
    $backup->permissions()->attach(Permission::where('code', 'admin.users.manage')->value('id'));
    Permissions::flush();

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('edit', $admin->id)
        ->set('roleIds', [$backup->id])
        ->call('save')
        ->assertHasNoErrors();
});

it('saves the language the app opens in', function () {
    $user = userWithRole('OPERATOR');

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->set('preferred_lang', 'ar')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->preferred_lang)->toBe('ar');
});

it('refuses an unknown language', function () {
    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('add')
        ->set('preferred_lang', 'de')
        ->assertHasErrors(['preferred_lang' => 'in']);
});

it('records new and changed accounts in the audit log, without the password', function () {
    $admin = userWithRole('ADMIN');
    $warehouse = Warehouse::factory()->create(['code' => 'BEY']);

    $component = Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('add')
        ->set('full_name', 'Rami Khoury')
        ->set('email', 'rami@example.test')
        ->set('password', 'secret-pass')
        ->set('roleIds', [roleId('OPERATOR')])
        ->set('warehouseIds', [$warehouse->id])
        ->call('save');

    $user = User::where('email', 'rami@example.test')->firstOrFail();

    $component->call('edit', $user->id)->set('active', false)->call('save')->assertSet('confirm.action', 'deactivate')->call('runConfirmed');

    $rows = DB::table('audit_log')->where('entity_id', $user->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['CREATE', 'UPDATE'])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and($rows[0]->before_data)->toBeNull()
        ->and(json_decode($rows[0]->after_data, true))->toMatchArray([
            'email' => 'rami@example.test',
            'roles' => ['OPERATOR'],
            'warehouses' => ['BEY'],
            'active' => true,
            'password_changed' => true,
        ])
        ->and(json_decode($rows[1]->before_data, true)['active'])->toBeTrue()
        ->and(json_decode($rows[1]->after_data, true)['active'])->toBeFalse()
        ->and($rows->implode('after_data'))->not->toContain('secret-pass')
        ->and($rows->implode('after_data'))->not->toContain($user->password_hash);
});

it('writes nothing to the audit log when nothing changed', function () {
    $user = userWithRole('OPERATOR');

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('audit_log')->count())->toBe(0);
});

it('is only for users who manage accounts', function () {
    Livewire::actingAs(userWithRole('MANAGER'))
        ->test(Users::class)
        ->assertForbidden();
});
