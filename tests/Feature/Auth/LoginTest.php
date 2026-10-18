<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

it('shows the sign in page to guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSeeLivewire(Login::class)
        ->assertSee(__('auth.submit'));
});

it('signs in an active user', function () {
    $user = userWithWarehouses();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

it('matches the email case-insensitively', function () {
    $user = User::factory()->create(['email' => 'operator@example.test']);

    Livewire::test(Login::class)
        ->set('email', 'Operator@Example.TEST')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('remembers the user when asked', function () {
    $user = User::factory()->create(['remember_token' => null]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->set('remember', true)
        ->call('login');

    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('rejects a wrong password', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee(__('auth.failed'));

    $this->assertGuest();
});

it('rejects an unknown email with the same message', function () {
    Livewire::test(Login::class)
        ->set('email', 'nobody@example.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee(__('auth.failed'));

    $this->assertGuest();
});

it('blocks an inactive user', function () {
    $user = User::factory()->inactive()->create();

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee(__('auth.inactive'));

    $this->assertGuest();
});

it('requires an email and a password', function () {
    Livewire::test(Login::class)
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('rate limits repeated failed attempts', function () {
    $user = User::factory()->create();

    $component = Livewire::test(Login::class)->set('email', $user->email);

    foreach (range(1, Login::MAX_ATTEMPTS) as $attempt) {
        $component->set('password', 'wrong-password')->call('login');
    }

    // Even the right password is refused until the limit expires.
    $component->set('password', 'password')->call('login')->assertHasErrors('email');

    expect($component->errors()->first('email'))->toStartWith(explode(':seconds', __('auth.throttle'))[0]);
    $this->assertGuest();
});

it('does not offer self-registration', function () {
    $this->get('/register')->assertNotFound();
});
