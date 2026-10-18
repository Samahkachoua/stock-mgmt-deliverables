<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Permission codes per role, read from the schema's permission / role_permission
 * tables and cached forever. Call flush() whenever role_permission changes.
 */
final class Permissions
{
    public const CACHE_KEY = 'authz.permissions';

    /**
     * Every permission code that exists.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::load()['codes'];
    }

    /**
     * @return array<string, list<string>> role id => permission codes
     */
    public static function byRole(): array
    {
        return self::load()['roles'];
    }

    public static function exists(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{codes: list<string>, roles: array<string, list<string>>}
     */
    private static function load(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $roles = [];

            $grants = DB::table('role_permission')
                ->join('permission', 'permission.id', '=', 'role_permission.permission_id')
                ->get(['role_permission.role_id', 'permission.code']);

            foreach ($grants as $grant) {
                $roles[$grant->role_id][] = $grant->code;
            }

            return [
                'codes' => DB::table('permission')->orderBy('code')->pluck('code')->all(),
                'roles' => $roles,
            ];
        });
    }
}
