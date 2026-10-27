<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Account menu › Change password: the signed-in user sets their own password
 * (an administrator can still set one in Settings › Users). Asks for the
 * current one first; afterwards the user's other sessions are signed out.
 */
#[Layout('components.layouts.app')]
class ChangePassword extends Component
{
    public const MIN_LENGTH = 8;

    /** Wrong current passwords per user and minute. */
    public const MAX_ATTEMPTS = 5;

    public string $current = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $key = 'change-password|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('current', __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        $this->validate();

        if (! Hash::check($this->current, $user->getAuthPassword())) {
            RateLimiter::hit($key);
            $this->addError('current', __('account.password.wrong_current'));

            return;
        }

        RateLimiter::clear($key);
        $guard = Auth::guard('web');
        $remembered = $guard instanceof SessionGuard && request()->hasCookie($guard->getRecallerName());

        DB::transaction(function () use ($user) {
            $user->password_hash = $this->password; // hashed by the model cast
            // A "keep me signed in" cookie on another device stops working.
            $user->setRememberToken(Str::random(60));
            $user->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->where('id', '!=', session()->getId())
                    ->delete();
            }

            // Never the password itself: only that the user changed it.
            Audit::record('app_user', $user->id, 'UPDATE', null, ['password_changed' => true]);
        });

        // This device stays signed in (with a fresh cookie if it had one).
        $guard->login($user, $remembered);
        session()->regenerate();

        // The user stays on the page: empty fields and a success toast.
        $this->reset('current', 'password', 'password_confirmation');
        $this->resetValidation();
        Notify::success(__('account.password.saved'), on: $this);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'current' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed', 'different:current'],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'current.required' => __('account.password.current_required'),
            'password.required' => __('account.password.new_required'),
            'password.min' => __('account.password.too_short', ['min' => self::MIN_LENGTH]),
            'password.confirmed' => __('account.password.mismatch'),
            'password.different' => __('account.password.same_as_current'),
            'password_confirmation.required' => __('account.password.confirm_required'),
        ];
    }

    public function render(): View
    {
        return view('livewire.account.change-password', [
            'minLength' => self::MIN_LENGTH,
        ])->title(__('account.password.title'));
    }
}
