<?php

use App\Livewire\Auth\Login;
use App\Livewire\Reports\ReportPage;
use App\Models\User;
use App\Reports\Reports;
use App\Support\Auth\IpCountry;
use App\Support\Auth\LoginLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function attemptLogin(string $email, string $password, bool $remember = false): void
{
    Livewire::test(Login::class)
        ->set('email', $email)
        ->set('password', $password)
        ->set('remember', $remember)
        ->call('login');
}

function lastLogin(): object
{
    return DB::table('login_log')->orderByDesc('id')->first();
}

it('records a successful sign-in with the user, address and device', function () {
    $user = userWithWarehouses();

    attemptLogin(strtoupper($user->email), 'password', remember: true);

    $row = lastLogin();
    expect($row->result)->toBe(LoginLog::SUCCESS)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->email)->toBe(strtolower($user->email))
        ->and($row->ip_address)->toBe('127.0.0.1')
        ->and($row->user_agent)->not->toBeEmpty()
        ->and((bool) $row->remember)->toBeTrue();
});

it('records a wrong password without storing it', function () {
    $user = User::factory()->create();

    attemptLogin($user->email, 'my-secret-guess');

    $row = lastLogin();
    expect($row->result)->toBe(LoginLog::WRONG_PASSWORD)
        ->and($row->user_id)->toBe($user->id)
        ->and(json_encode($row))->not->toContain('my-secret-guess');
});

it('records an unknown email with no user', function () {
    attemptLogin('nobody@example.test', 'password');

    $row = lastLogin();
    expect($row->result)->toBe(LoginLog::UNKNOWN_EMAIL)
        ->and($row->user_id)->toBeNull()
        ->and($row->email)->toBe('nobody@example.test');
});

it('records an inactive user', function () {
    $user = User::factory()->inactive()->create();

    attemptLogin($user->email, 'password');

    expect(lastLogin())->result->toBe(LoginLog::INACTIVE)->user_id->toBe($user->id);
});

it('records attempts refused by the rate limit', function () {
    $user = User::factory()->create();

    foreach (range(1, Login::MAX_ATTEMPTS + 1) as $attempt) {
        attemptLogin($user->email, 'wrong-password');
    }

    expect(lastLogin()->result)->toBe(LoginLog::THROTTLED)
        ->and(DB::table('login_log')->where('result', LoginLog::WRONG_PASSWORD)->count())->toBe(Login::MAX_ATTEMPTS);
});

it('does not record a form with no email', function () {
    attemptLogin('', '');

    expect(DB::table('login_log')->count())->toBe(0);
});

it('shows the login history to admins and auditors only', function () {
    $admin = userWithRole('ADMIN');
    $auditor = userWithRole('AUDITOR');

    expect($admin->hasPermission('report.login_history.view'))->toBeTrue()
        ->and($auditor->hasPermission('report.login_history.view'))->toBeTrue()
        ->and(userWithRole('MANAGER')->hasPermission('report.login_history.view'))->toBeFalse()
        ->and(userWithRole('OPERATOR')->hasPermission('report.login_history.view'))->toBeFalse()
        ->and(userWithRole('CUSTOMER')->hasPermission('report.login_history.view'))->toBeFalse();

    $this->actingAs(userWithRole('MANAGER'))->get(route('reports.show', 'login-history'))->assertForbidden();
    $this->actingAs($auditor)->get(route('reports.show', 'login-history'))->assertOk()
        ->assertSee(Reports::get('login-history')->title());
});

it('filters the login history by result and search', function () {
    $user = User::factory()->create(['full_name' => 'Nour Haddad']);
    attemptLogin($user->email, 'password');
    attemptLogin($user->email, 'wrong-password');
    attemptLogin('stranger@example.test', 'password');

    $admin = userWithRole('ADMIN');
    session()->forget('list_state');
    $page = fn (array $filters) => Livewire::actingAs($admin)
        ->test(ReportPage::class, ['report' => 'login-history'])
        ->set('filters', $filters)
        ->viewData('rows');

    expect($page([])->total())->toBe(3)
        ->and($page(['result' => 'FAILED'])->total())->toBe(2)
        ->and($page(['result' => LoginLog::SUCCESS])->total())->toBe(1)
        ->and($page(['search' => 'nour'])->total())->toBe(2)
        ->and($page(['search' => 'stranger'])->items()[0]['cells']['result']['text'])
        ->toBe(__('reports.login_history.results.UNKNOWN_EMAIL'));
});

it('records the country of the sign-in address', function () {
    app()->instance(IpCountry::class, new class extends IpCountry
    {
        public function lookup(?string $ip): ?string
        {
            return 'LB';
        }
    });
    $user = userWithWarehouses();

    attemptLogin($user->email, 'password');

    expect(lastLogin()->country)->toBe('LB');

    app()->setLocale('ar');
    $row = Reports::get('login-history')->row((object) [...(array) lastLogin(), 'full_name' => $user->full_name], []);
    expect($row['country'])->toBe('لبنان');
});

it('leaves the country empty for local and private addresses', function (string $ip) {
    config(['services.maxmind.database' => base_path('composer.json')]);

    expect((new IpCountry)->lookup($ip))->toBeNull();
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.20', '::1', 'not-an-ip']);

it('leaves the country empty while the GeoLite2 file is missing', function () {
    config(['services.maxmind.database' => storage_path('app/private/geoip/missing.mmdb')]);

    expect((new IpCountry)->lookup('8.8.8.8'))->toBeNull();

    attemptLogin('nobody@example.test', 'password');
    expect(lastLogin()->country)->toBeNull();
});

it('skips the GeoLite2 download without a MaxMind account', function () {
    config(['services.maxmind.account_id' => null, 'services.maxmind.license_key' => null]);
    Http::fake();

    $this->artisan('geoip:update')->assertSuccessful();

    Http::assertNothingSent();
});
