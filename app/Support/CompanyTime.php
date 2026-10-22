<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's time zone (Settings › General, app_setting 'timezone'): every
 * warehouse works in it. Times are stored in UTC (TIMESTAMPTZ, and the app
 * itself runs in UTC); this decides what "today" is (form defaults, the
 * future-date check, expired stock, document number years) and how stored
 * times are shown.
 */
final class CompanyTime
{
    public const DEFAULT = 'Asia/Beirut';

    private const KEY = 'timezone';

    private const CACHE = 'app_setting.timezone';

    /** Memo for this request (the class is a singleton). */
    private ?string $zone = null;

    public static function zone(): string
    {
        $self = app(self::class);

        return $self->zone ??= Cache::rememberForever(self::CACHE, fn () => self::read());
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /**
     * Midnight today in the company's time zone.
     */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * A stored instant (UTC) as it is in the company's time zone.
     */
    public static function local(DateTimeInterface|string|null $instant): ?CarbonImmutable
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        return ($instant instanceof DateTimeInterface ? CarbonImmutable::instance($instant) : CarbonImmutable::parse($instant))
            ->setTimezone(self::zone());
    }

    /**
     * Whether $zone is a time zone PHP and Postgres both know (an IANA name).
     */
    public static function valid(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function set(string $zone, ?string $userId): void
    {
        DB::table('app_setting')->upsert(
            [['key' => self::KEY, 'value' => $zone, 'updated_by' => $userId, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_by', 'updated_at'],
        );

        self::flush();
    }

    public static function flush(): void
    {
        app(self::class)->zone = null;
        Cache::forget(self::CACHE);
    }

    private static function read(): string
    {
        // Not caught as an error: that would abort an open Postgres transaction.
        $zone = Schema::hasTable('app_setting')
            ? DB::table('app_setting')->where('key', self::KEY)->value('value')
            : null;

        return is_string($zone) && self::valid($zone) ? $zone : self::DEFAULT;
    }
}
