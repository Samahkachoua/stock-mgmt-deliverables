<?php

namespace App\Jobs;

use App\Mail\PasswordResetLinkEmail;
use App\Models\User;
use App\Support\Auth\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Creates a reset token and emails the link, in the queue worker. The page
 * only queues this job, so it answers the same way (and as fast) whether the
 * email is registered or not. The email is sent inside the job, not queued
 * again, so the plain token is never written to the jobs tables. Unknown and
 * disabled accounts get nothing.
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the broker's throttle (60 s), which would otherwise skip a retry. */
    public array $backoff = [90, 300];

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        Password::broker()->sendResetLink(
            PasswordReset::credentials($this->email),
            function (User $user, string $token): void {
                Mail::to($user->email)
                    ->locale(PasswordReset::locale($user))
                    ->send(new PasswordResetLinkEmail($user->full_name, PasswordReset::url($user, $token)));
            },
        );
    }
}
