<?php

use App\Livewire\Account\ChangePassword;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Settings\Users;
use App\Mail\PasswordChangedEmail;
use App\Mail\PasswordResetLinkEmail;
use App\Models\User;
use App\Support\Auth\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** The link's token + email, as PasswordResetLinkController leaves them in the session. */
function openResetLink(User $user, string $token): void
{
    session()->put(PasswordReset::SESSION_KEY, ['token' => $token, 'email' => $user->email]);
}

function requestReset(string $email): Testable
{
    return Livewire::test(ForgotPassword::class)->set('email', $email)->call('send');
}

beforeEach(function () {
    Mail::fake();
});

it('links to Forgot password from the sign in page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('password.request'))
        ->assertSee(__('auth.forgot.link'));

    $this->get(route('password.request'))->assertOk()->assertSeeLivewire(ForgotPassword::class);
});

it('emails a link to an active user and stores only a hash of its token', function () {
    $user = userWithWarehouses();

    requestReset(strtoupper($user->email))
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee(__('auth.forgot.sent'));

    $sent = null;
    Mail::assertSent(PasswordResetLinkEmail::class, function (PasswordResetLinkEmail $mail) use ($user, &$sent) {
        $sent = $mail;

        return $mail->hasTo($user->email);
    });

    $token = str($sent->url)->after('/reset-password/')->before('?')->toString();
    $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

    expect($token)->not->toBe('')
        ->and($stored)->not->toBe($token)
        ->and(Hash::check($token, $stored))->toBeTrue();
});

it('answers an unknown email exactly like a registered one and sends nothing', function () {
    $known = userWithWarehouses();

    $forKnown = requestReset($known->email)->html();
    $forUnknown = requestReset('nobody@example.test')->html();

    $strip = fn (string $html) => preg_replace('/wire:(snapshot|id)="[^"]*"/', '', $html);

    expect($strip($forUnknown))->toBe($strip($forKnown));
    Mail::assertSentCount(1);
    expect(DB::table('password_reset_tokens')->count())->toBe(1);
});

it('sends nothing to a disabled account, with the same message', function () {
    $user = userWithWarehouses();
    $user->forceFill(['active' => false])->save();

    requestReset($user->email)->assertHasNoErrors()->assertSee(__('auth.forgot.sent'));

    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('builds the link from APP_URL, not the request host', function () {
    config(['app.url' => 'https://wms.example.com']);
    $user = userWithWarehouses();

    $this->withServerVariables(['HTTP_HOST' => 'evil.example.net']);
    requestReset($user->email);

    Mail::assertSent(PasswordResetLinkEmail::class, fn ($mail) => str_starts_with($mail->url, 'https://wms.example.com/reset-password/'));
});

it('limits requests per email and per IP address', function () {
    $user = userWithWarehouses();

    foreach (range(1, ForgotPassword::MAX_ATTEMPTS) as $i) {
        requestReset($user->email)->assertHasNoErrors();
    }

    requestReset($user->email)->assertHasErrors('email');
    // Other emails from the same address are limited too.
    requestReset('someone-else@example.test')->assertHasErrors('email');
});

it('moves the link into the session and leaves a URL without the token', function () {
    $user = userWithWarehouses();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertRedirect(route('password.reset.form'))
        ->assertSessionHas(PasswordReset::SESSION_KEY, ['token' => $token, 'email' => $user->email]);

    expect(route('password.reset.form'))->not->toContain($token);
});

it('gives both password fields an eye button that starts hidden', function () {
    $user = userWithWarehouses();
    openResetLink($user, Password::broker()->createToken($user));

    Livewire::test(ResetPassword::class)
        ->assertSeeHtml('aria-controls="password"')
        ->assertSeeHtml('aria-controls="password_confirmation"')
        ->assertSeeHtml('x-bind:type="shown ? \'text\' : \'password\'"')
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSee(__('auth.show_password'));
});

it('resets the password, uses up the token and signs out every device', function () {
    $user = userWithWarehouses();
    $rememberToken = $user->fresh()->remember_token;
    $user->forceFill(['must_change_password' => true])->save();
    DB::table('sessions')->insert([
        ['id' => 'phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
    ]);
    openResetLink($user, Password::broker()->createToken($user));

    Livewire::test(ResetPassword::class)
        ->assertSet('invalid', false)
        ->set('password', 'brand-new-pass')
        ->set('password_confirmation', 'brand-new-pass')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    $user = $user->fresh();

    expect(Hash::check('brand-new-pass', $user->password_hash))->toBeTrue()
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and($user->must_change_password)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(session(PasswordReset::SESSION_KEY))->toBeNull();

    Mail::assertQueued(PasswordChangedEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $audit = DB::table('audit_log')->where('entity', 'app_user')->where('entity_id', $user->id)->latest('id')->first();
    expect(json_decode($audit->after_data, true))->toBe(['password_reset' => true])
        ->and($audit->user_id)->toBe($user->id);
});

it('refuses a link that was already used', function () {
    $user = userWithWarehouses();
    $token = Password::broker()->createToken($user);
    openResetLink($user, $token);

    Livewire::test(ResetPassword::class)
        ->set('password', 'brand-new-pass')
        ->set('password_confirmation', 'brand-new-pass')
        ->call('save')
        ->assertHasNoErrors();

    openResetLink($user, $token);

    Livewire::test(ResetPassword::class)
        ->assertSet('invalid', true)
        ->assertSee(__('auth.reset.request_new'))
        ->assertSee(route('password.request'));
});

it('refuses an expired link', function () {
    $user = userWithWarehouses();
    openResetLink($user, Password::broker()->createToken($user));

    $this->travel(PasswordReset::expiresIn() + 1)->minutes();

    Livewire::test(ResetPassword::class)->assertSet('invalid', true);
});

it('refuses a link replaced by a newer one', function () {
    $user = userWithWarehouses();
    $old = Password::broker()->createToken($user);
    DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(2)]);
    Password::broker()->createToken($user);
    openResetLink($user, $old);

    Livewire::test(ResetPassword::class)->assertSet('invalid', true);
});

it('refuses a wrong token', function () {
    $user = userWithWarehouses();
    Password::broker()->createToken($user);
    openResetLink($user, 'not-the-token');

    Livewire::test(ResetPassword::class)->assertSet('invalid', true);
});

it('refuses the link of an account disabled since it was sent', function () {
    $user = userWithWarehouses();
    openResetLink($user, Password::broker()->createToken($user));
    $user->forceFill(['active' => false])->save();

    Livewire::test(ResetPassword::class)->assertSet('invalid', true);
    expect(Hash::check('password', $user->fresh()->password_hash))->toBeTrue();
});

it('follows the password rules', function (string $password, string $confirmation, string $message) {
    $user = userWithWarehouses();
    openResetLink($user, Password::broker()->createToken($user));

    Livewire::test(ResetPassword::class)
        ->set('password', $password)
        ->set('password_confirmation', $confirmation)
        ->call('save')
        ->assertHasErrors('password')
        ->assertSee($message);

    expect(Hash::check('password', $user->fresh()->password_hash))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
})->with([
    'too short' => ['short', 'short', fn () => __('account.password.too_short', ['min' => ChangePassword::MIN_LENGTH])],
    'not repeated the same' => ['brand-new-pass', 'brand-new-pazz', fn () => __('account.password.mismatch')],
    'the old password' => ['password', 'password', fn () => __('account.password.same_as_current')],
]);

it('limits reset attempts per IP address', function () {
    $user = userWithWarehouses();
    openResetLink($user, Password::broker()->createToken($user));

    $page = Livewire::test(ResetPassword::class);

    foreach (range(1, ResetPassword::MAX_ATTEMPTS) as $i) {
        $page->set('password', 'short')->set('password_confirmation', 'short')->call('save');
    }

    $page->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')
        ->call('save')
        ->assertHasErrors('password')
        ->assertNoRedirect();

    expect(Hash::check('password', $user->fresh()->password_hash))->toBeTrue();
});

it('renders the reset email with the button, the plain link and the expiry, in Arabic right to left', function () {
    $mail = new PasswordResetLinkEmail('Nour', 'https://wms.example.com/reset-password/abc?email=n%40example.test');

    $mail->assertSeeInHtml('https://wms.example.com/reset-password/abc?email=n%40example.test', false)
        ->assertSeeInHtml(__('auth.mail.reset.button'))
        ->assertSeeInHtml(__('auth.mail.reset.expires', ['minutes' => 60]))
        ->assertSeeInHtml(__('auth.mail.reset.ignore'))
        ->assertSeeInText('https://wms.example.com/reset-password/abc?email=n%40example.test');

    $arabic = (new PasswordResetLinkEmail('Nour', 'https://wms.example.com/reset-password/abc'))->locale('ar');
    $arabic->assertSeeInHtml('dir="rtl"', false)->assertSeeInHtml(__('auth.mail.reset.button', [], 'ar'));
});

// Settings › Users: the administrator's fallback.

it('lets an administrator email a user the reset link, and audits it', function () {
    $user = userWithRole('OPERATOR');

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->assertSee(__('settings.users.send_reset_link'))
        ->call('sendResetLink', $user->id);

    Mail::assertSent(PasswordResetLinkEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $audit = DB::table('audit_log')->where('entity', 'app_user')->where('entity_id', $user->id)->latest('id')->first();
    expect(json_decode($audit->after_data, true))->toBe(['password_reset_link_sent' => true]);
});

it('sends no reset link to a disabled account', function () {
    $user = userWithRole('OPERATOR');
    $user->forceFill(['active' => false])->save();

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('sendResetLink', $user->id);

    Mail::assertNothingSent();
});

it('makes a password typed by an administrator temporary', function () {
    $user = userWithRole('OPERATOR');

    Livewire::actingAs(userWithRole('ADMIN'))
        ->test(Users::class)
        ->call('edit', $user->id)
        ->set('password', 'temporary-1')
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();
    expect($user->must_change_password)->toBeTrue();

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('account.password'));
    $this->actingAs($user)->get(route('account.password'))->assertOk()->assertSee(__('auth.forced_change'));

    Livewire::actingAs($user)
        ->test(ChangePassword::class)
        ->set('current', 'temporary-1')
        ->set('password', 'my-own-pass')
        ->set('password_confirmation', 'my-own-pass')
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();
    expect($user->must_change_password)->toBeFalse();
    Mail::assertQueued(PasswordChangedEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->actingAs($user)->get(route('home'))->assertOk();
});

it('does not make an administrator\'s own new password temporary', function () {
    $admin = userWithRole('ADMIN');

    Livewire::actingAs($admin)
        ->test(Users::class)
        ->call('edit', $admin->id)
        ->set('password', 'admin-new-pass')
        ->call('save')
        ->assertHasNoErrors();

    expect($admin->fresh()->must_change_password)->toBeFalse();
});
