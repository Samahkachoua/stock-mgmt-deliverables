<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Login history (table login_log, report LoginHistoryReport): one row per
 * sign-in attempt, successful or not. The password is never stored.
 */
final class LoginLog
{
    public const SUCCESS = 'SUCCESS';

    public const WRONG_PASSWORD = 'WRONG_PASSWORD';

    public const UNKNOWN_EMAIL = 'UNKNOWN_EMAIL';

    public const INACTIVE = 'INACTIVE';

    public const THROTTLED = 'THROTTLED';

    /** Every result, successful first (the report's filter and names). */
    public const RESULTS = [self::SUCCESS, self::WRONG_PASSWORD, self::UNKNOWN_EMAIL, self::INACTIVE, self::THROTTLED];

    /**
     * Saves one attempt. A failure here is logged but never stops the sign-in.
     */
    public static function record(?User $user, string $email, string $result, bool $remember = false): void
    {
        try {
            $request = request();
            $agent = $request->userAgent();

            DB::table('login_log')->insert([
                'user_id' => $user?->getKey(),
                'email' => Str::limit(Str::lower(trim($email)), 255, ''),
                'result' => $result,
                'ip_address' => (string) $request->ip(),
                'country' => app(IpCountry::class)->lookup($request->ip()),
                'user_agent' => $agent === null || $agent === '' ? null : mb_substr($agent, 0, 500),
                'remember' => $remember,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
