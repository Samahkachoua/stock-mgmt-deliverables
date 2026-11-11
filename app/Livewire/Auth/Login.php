<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\Auth\LoginLog;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    public const MAX_ATTEMPTS = 5;

    /** Failed sign-ins per IP address and minute, whatever the email (password spraying). */
    public const MAX_ATTEMPTS_PER_IP = 20;

    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $key = $this->throttleKey();
        $ipKey = 'login-ip|'.request()->ip();

        foreach ([$key => self::MAX_ATTEMPTS, $ipKey => self::MAX_ATTEMPTS_PER_IP] as $limited => $max) {
            if (RateLimiter::tooManyAttempts($limited, $max)) {
                LoginLog::record(null, $this->email, LoginLog::THROTTLED, $this->remember);
                $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($limited)]));

                return;
            }
        }

        /** @var UserProvider $provider */
        $provider = Auth::getProvider();

        // Emails are unique case-insensitively (uq_app_user_email on lower(email)).
        $credentials = [
            'email' => fn ($query) => $query->whereRaw('lower(email) = lower(?)', [$this->email]),
            'password' => $this->password,
        ];

        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            LoginLog::record(
                $user instanceof User ? $user : null,
                $this->email,
                $user instanceof User ? LoginLog::WRONG_PASSWORD : LoginLog::UNKNOWN_EMAIL,
                $this->remember,
            );
            RateLimiter::hit($key);
            RateLimiter::hit($ipKey);
            $this->addError('email', __('auth.failed'));

            return;
        }

        // Only said once the password is right, so it does not reveal which emails exist.
        if (! $user->active) {
            LoginLog::record($user, $this->email, LoginLog::INACTIVE, $this->remember);
            RateLimiter::hit($key);
            $this->addError('email', __('auth.inactive'));

            return;
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        RateLimiter::clear($key);
        Auth::login($user, $this->remember);
        session()->regenerate();
        LoginLog::record($user, $this->email, LoginLog::SUCCESS, $this->remember);

        $this->redirectIntended(route('home'));
    }

    public function render(): View
    {
        return view('livewire.auth.login')->title(__('auth.title'));
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
