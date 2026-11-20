<?php

namespace App\Livewire\Auth;

use App\Livewire\Account\ChangePassword;
use App\Models\User;
use App\Support\Audit;
use App\Support\Auth\PasswordReset;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Choose a new password from an emailed link. The link's token and email wait
 * in the session (PasswordResetLinkController), never in this component's
 * public state. On success every session and "keep me signed in" cookie of
 * the user stops working and the token is used up.
 */
#[Layout('components.layouts.guest')]
class ResetPassword extends Component
{
    /** Attempts per IP address in DECAY_SECONDS. */
    public const MAX_ATTEMPTS = 10;

    public const DECAY_SECONDS = 15 * 60;

    /** The link is unknown, used or expired: show how to ask for a new one. */
    public bool $invalid = false;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->invalid = $this->user() === null;
    }

    public function save(): void
    {
        $key = 'reset-password|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('password', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $user = $this->user();

        if ($user === null) {
            $this->invalid = true;

            return;
        }

        $this->validate();

        if (Hash::check($this->password, $user->getAuthPassword())) {
            $this->addError('password', __('account.password.same_as_current'));

            return;
        }

        /** @var array{token: string, email: string} $link */
        $link = session(PasswordReset::SESSION_KEY);

        $status = DB::transaction(function () use ($user, $link) {
            // Two tabs sending the same link: the second waits here, then finds the token gone.
            DB::table('password_reset_tokens')->where('email', $user->getEmailForPasswordReset())->lockForUpdate()->first();

            return Password::broker()->reset(
                PasswordReset::credentials($link['email']) + [
                    'token' => $link['token'],
                    'password' => $this->password,
                ],
                function (User $user, string $password): void {
                    $user->password_hash = $password; // hashed by the model cast
                    $user->must_change_password = false;
                    // A "keep me signed in" cookie on any device stops working.
                    $user->setRememberToken(Str::random(60));
                    $user->save();

                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

                    // Never the password or token: only that it was reset from an emailed link.
                    Audit::record('app_user', $user->id, 'UPDATE', null, ['password_reset' => true], $user->id);
                    PasswordReset::notifyChanged($user);
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET) {
            $this->invalid = true;

            return;
        }

        session()->forget(PasswordReset::SESSION_KEY);
        Notify::success(__('auth.reset.done'));
        $this->redirectRoute('login');
    }

    /**
     * The link's active user while its token is still valid; null otherwise.
     */
    private function user(): ?User
    {
        $link = session(PasswordReset::SESSION_KEY);

        if (! is_array($link) || ! is_string($link['token'] ?? null) || ! is_string($link['email'] ?? null)) {
            return null;
        }

        $broker = Password::broker();
        $user = $broker->getUser(PasswordReset::credentials($link['email']));

        return $user instanceof User && $broker->tokenExists($user, $link['token']) ? $user : null;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:'.ChangePassword::MIN_LENGTH, 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'password.required' => __('account.password.new_required'),
            'password.min' => __('account.password.too_short', ['min' => ChangePassword::MIN_LENGTH]),
            'password.confirmed' => __('account.password.mismatch'),
            'password_confirmation.required' => __('account.password.confirm_required'),
        ];
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password', ['minLength' => ChangePassword::MIN_LENGTH])
            ->title(__('auth.reset.title'))
            ->layoutData(['photo' => true]);
    }
}
