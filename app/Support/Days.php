<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Whole calendar days between dates, ignoring the time of day and time zones.
 *
 * Comparing "midnight in Beirut" with a date read as midnight UTC is a few
 * hours off, and casting the fractional difference to int then loses a day
 * (stock that expired yesterday showed 0 days left).
 */
final class Days
{
    /**
     * Days from today in $timezone until $date (Y-m-d or a date): 0 today,
     * negative once it is past.
     */
    public static function until(string|DateTimeInterface $date, string $timezone): int
    {
        return self::between(CarbonImmutable::now($timezone), $date);
    }

    /**
     * Calendar days from $from to $to, each taken as the date it shows.
     */
    public static function between(string|DateTimeInterface $from, string|DateTimeInterface $to): int
    {
        return (int) round(self::day($from)->diffInDays(self::day($to), false));
    }

    private static function day(string|DateTimeInterface $date): CarbonImmutable
    {
        $text = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $text, 'UTC');
    }
}
