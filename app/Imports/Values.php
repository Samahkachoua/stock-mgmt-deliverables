<?php

namespace App\Imports;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Reading what people type into spreadsheets.
 */
final class Values
{
    /** Accepted for "yes" / "no" in every language (case ignored). */
    private const YES = ['y', 'yes', '1', 'true', 'o', 'oui', 'نعم'];

    private const NO = ['n', 'no', '0', 'false', 'non', 'لا', ''];

    /**
     * Y / N flags: true, false, or null when it is neither. Empty means N.
     */
    public static function flag(string $value): ?bool
    {
        $value = mb_strtolower(trim($value));

        return match (true) {
            in_array($value, self::YES, true) => true,
            in_array($value, self::NO, true) => false,
            default => null,
        };
    }

    /**
     * A number as text ("12", "1.5", "1,5"), or null. At most $decimals decimals.
     */
    public static function number(string $value, int $decimals = 4): ?string
    {
        $value = str_replace(' ', '', trim($value));

        if (substr_count($value, ',') === 1 && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value) || preg_match('/[eE]/', $value)) {
            return null;
        }

        if (preg_match('/\.(\d+)$/', $value, $m) && strlen(rtrim($m[1], '0')) > $decimals) {
            return null;
        }

        return $value;
    }

    /**
     * A date typed as 2027-03-31, 31/03/2027, 31-03-2027 or 31.03.2027 (or read
     * from an Excel date cell), as Y-m-d; null when it isn't a real date.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            // 31/02/2027 would roll over to March: only real dates.
            if ($date !== null && $date->format(ltrim($format, '!')) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * The closest of $candidates to $value (case ignored), when it is close
     * enough to be a likely typo; for "Did you mean …?".
     *
     * @param  iterable<string>  $candidates
     */
    public static function closest(string $value, iterable $candidates): ?string
    {
        $value = mb_strtoupper(trim($value));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($value, mb_strtoupper($candidate));

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        $allowed = max(2, intdiv(mb_strlen($value), 3));

        return $best !== null && $bestDistance <= $allowed ? $best : null;
    }
}
