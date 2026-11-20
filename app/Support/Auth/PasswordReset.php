<?php

namespace App\Support\Auth;

use App\Mail\PasswordChangedEmail;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Mail;

/**
 * Shared by "Forgot password?" (App\Livewire\Auth\ForgotPassword /
 * ResetPassword), the job that sends the link and Settings › Users.
 * Built on Laravel's password broker (config/auth.php passwords.users): the
 * token is stored hashed in password_reset_tokens, one per email, and is
 * deleted when used; a new link replaces the previous one.
 */
final class PasswordReset
{
    /** Session key holding the token + email between the link and the form (kept out of the URL). */
    public const SESSION_KEY = 'password_reset';

    /**
     * Broker credentials: emails are unique case-insensitively (uq_app_user_email),
     * and a disabled account never gets a link nor can use one.
     *
     * @return array{email: Closure, active: true}
     */
    public static function credentials(string $email): array
    {
        return [
            'email' => fn ($query) => $query->whereRaw('lower(email) = lower(?)', [trim($email)]),
            'active' => true,
        ];
    }

    /** Minutes a link stays valid. */
    public static function expiresIn(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    /**
     * The link in the email, always from APP_URL, never the request's Host header.
     */
    public static function url(User $user, string $token): string
    {
        return rtrim((string) config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->email], absolute: false);
    }

    /** The language of emails to this user. */
    public static function locale(User $user): string
    {
        return $user->preferred_lang ?: (string) config('app.locale');
    }

    /** "Your password was changed": queued, sent after the transaction commits. */
    public static function notifyChanged(User $user): void
    {
        Mail::to($user->email)
            ->locale(self::locale($user))
            ->queue(new PasswordChangedEmail($user->full_name, now()->toIso8601String()));
    }
}
