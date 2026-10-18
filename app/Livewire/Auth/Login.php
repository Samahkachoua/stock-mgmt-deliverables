<?php

namespace App\Livewire\Auth;

use App\Models\User;
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
            RateLimiter::hit($key);
            RateLimiter::hit($ipKey);
            $this->addError('email', __('auth.failed'));

            return;
        }

        // Only said once the password is right, so it does not reveal which emails exist.
        if (! $user->active) {
            RateLimiter::hit($key);
            $this->addError('email', __('auth.inactive'));

            return;
        }

        $provider->rehashPasswordIfRequired($user, $credentials);

        RateLimiter::clear($key);
        Auth::login($user, $this->remember);
        session()->regenerate();

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
