<?php

use App\Livewire\Account\ChangePassword;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('shows Change password in the account menu', function () {
    $this->actingAs(userWithWarehouses())
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('account.password'))
        ->assertSee(__('account.password.menu'));
});

it('opens the page for any signed-in user', function () {
    $this->actingAs(userWithWarehouses())
        ->get(route('account.password'))
        ->assertOk()
        ->assertSee(__('account.password.current'));
});

it('sends guests to the sign in page', function () {
    $this->get(route('account.password'))->assertRedirect(route('login'));
});

it('changes the password and audits it without the password', function () {
    $user = userWithWarehouses();

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current', 'password')
        ->set('password', 'new-secret-1')
        ->set('password_confirmation', 'new-secret-1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('current', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '')
        ->assertDispatched(Notify::EVENT);

    expect(Hash::check('new-secret-1', $user->fresh()->password_hash))->toBeTrue();

    $audit = DB::table('audit_log')->where('entity', 'app_user')->where('entity_id', $user->id)->latest('id')->first();
    expect(json_decode($audit->after_data, true))->toBe(['password_changed' => true]);
});

it('signs out the user\'s other sessions', function () {
    config(['session.driver' => 'database']);
    $user = userWithWarehouses();
    DB::table('sessions')->insert([
        'id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
    ]);

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current', 'password')
        ->set('password', 'new-secret-1')
        ->set('password_confirmation', 'new-secret-1')
        ->call('save');

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

it('refuses a wrong current password', function () {
    $user = userWithWarehouses();

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current', 'not-it')
        ->set('password', 'new-secret-1')
        ->set('password_confirmation', 'new-secret-1')
        ->call('save')
        ->assertHasErrors(['current']);

    expect(Hash::check('password', $user->fresh()->password_hash))->toBeTrue();
});

it('checks the new password', function (string $new, string $repeat, string $rule) {
    Livewire::actingAs(userWithWarehouses())
        ->test(ChangePassword::class)
        ->set('current', 'password')
        ->set('password', $new)
        ->set('password_confirmation', $repeat)
        ->call('save')
        ->assertHasErrors(['password' => $rule]);
})->with([
    'too short' => ['short', 'short', 'min'],
    'not repeated the same' => ['new-secret-1', 'new-secret-2', 'confirmed'],
    'same as the current one' => ['password', 'password', 'different'],
]);

it('stops guessing the current password after a few tries', function () {
    $component = Livewire::actingAs(userWithWarehouses())->test(ChangePassword::class)
        ->set('password', 'new-secret-1')
        ->set('password_confirmation', 'new-secret-1')
        ->set('current', 'wrong');

    foreach (range(1, ChangePassword::MAX_ATTEMPTS) as $ignored) {
        $component->call('save');
    }

    $component->set('current', 'password')->call('save')->assertHasErrors(['current']);
});
