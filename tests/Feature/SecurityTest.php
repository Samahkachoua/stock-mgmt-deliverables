<?php

use App\Livewire\Auth\Login;
use App\Livewire\Settings\Warehouses;
use App\Livewire\Transactions\TransactionForm;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('sends the browser protection headers', function () {
    $this->get(route('login'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('does not serve the private file disk over HTTP', function () {
    $paths = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect($paths)->not->toContain('storage/{path}');
});

it('limits failed sign-ins per IP address, whatever the email', function () {
    RateLimiter::clear('login-ip|127.0.0.1');
    $user = userWithRole('OPERATOR');

    foreach (range(1, Login::MAX_ATTEMPTS_PER_IP) as $i) {
        Livewire::test(Login::class)->set('email', "nobody$i@example.test")->set('password', 'wrong')->call('login');
    }

    // Even the right password for a real account waits now.
    Livewire::test(Login::class)
        ->set('email', $user->email)->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
    RateLimiter::clear('login-ip|127.0.0.1');
});

it('does not let the browser change ids that only the server may set', function (string $component, array $params, string $property) {
    $user = userWithRole('ADMIN');

    expect(fn () => Livewire::actingAs($user)->test($component, $params)->set($property, Warehouse::factory()->create()->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'transaction customer' => [TransactionForm::class, ['type' => 'TRANSFER'], 'customerId'],
    'record being edited' => [Warehouses::class, [], 'editing'],
]);

it('creates the first administrator and warehouse on an empty system', function () {
    Warehouse::query()->update(['active' => false]);

    $this->artisan('user:create-admin', ['--email' => 'Boss@Example.test', '--name' => 'First Admin', '--warehouse-code' => 'bey', '--warehouse-name' => 'Beirut Main', '--warehouse-city' => 'Beirut'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->assertSuccessful();

    $admin = User::where('email', 'Boss@Example.test')->sole();
    expect($admin->hasPermission('admin.users.manage'))->toBeTrue()
        ->and($admin->warehouseIds())->toHaveCount(1)
        ->and(Warehouse::where('code', 'BEY')->exists())->toBeTrue()
        ->and(Hash::check('a-long-enough-password', $admin->password_hash))->toBeTrue();

    // A second account with the same email (any case) is refused; short passwords too.
    $this->artisan('user:create-admin', ['--email' => 'boss@example.test', '--name' => 'Again'])
        ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
        ->assertFailed();
    $this->artisan('user:create-admin', ['--email' => 'other@example.test', '--name' => 'Other'])
        ->expectsQuestion('Password (at least 12 characters)', 'short')
        ->assertFailed();
});
