<?php

namespace App\Livewire\Auth;

use App\Jobs\SendPasswordResetLink;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * "Forgot password?": asks for the email and queues SendPasswordResetLink.
 * Always answers with the same message, so it never tells whether an email
 * is registered (or disabled); the job decides whether anything is sent.
 */
#[Layout('components.layouts.guest')]
class ForgotPassword extends Component
{
    /** Requests per email, and per IP address, in DECAY_SECONDS. */
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 15 * 60;

    #[Validate('required|string|email|max:255')]
    public string $email = '';

    /** The request was accepted: show the generic message instead of the form. */
    public bool $sent = false;

    public function send(): void
    {
        $this->email = trim($this->email);
        $this->validate();

        $keys = ['forgot|'.Str::transliterate(Str::lower($this->email)), 'forgot-ip|'.request()->ip()];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

                return;
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        SendPasswordResetLink::dispatch($this->email);

        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password')
            ->title(__('auth.forgot.title'))
            ->layoutData(['photo' => true]);
    }
}
