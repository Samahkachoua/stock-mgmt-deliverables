<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;

/**
 * Locale-aware display formatting (CLAUDE.md: numbers, quantities and dates are
 * formatted per locale). Codes, SKUs and references are never formatted.
 */
final class Format
{
    public static function qty(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) Number::format((float) $value, maxPrecision: 4, locale: self::numberLocale());
    }

    /**
     * Arabic keeps Western digits (what people type into the quantity fields and
     * what dates use) with Arabic separators, so a screen never mixes 12 and ١٢.
     */
    public static function numberLocale(): string
    {
        $locale = app()->getLocale();

        return $locale === 'ar' ? 'ar@numbers=latn' : $locale;
    }

    /**
     * A quantity with its unit name: "36 Piece".
     */
    public static function qtyUnit(float|int|string|null $value, string $unit): string
    {
        return self::qty($value).' '.self::unit($unit);
    }

    /**
     * Unit name from lang/<locale>/units.php by unit code, falling back to the
     * name stored in the unit table, then to the code.
     */
    public static function unit(string $code): string
    {
        if (Lang::has('units.'.$code)) {
            return __('units.'.$code);
        }

        return self::unitNames()[$code] ?? $code;
    }

    /**
     * @return array<string, string> unit code => stored name (once per request)
     */
    private static function unitNames(): array
    {
        return once(fn () => DB::table('unit')->pluck('name', 'code')->all());
    }

    /**
     * "3 items · 212 Piece": the unit is named when every line shares one base
     * unit, otherwise the total is in "units".
     *
     * @param  list<string>  $baseUnits  base unit code of each line
     */
    public static function itemsTotal(int $items, float $total, array $baseUnits): string
    {
        $units = array_values(array_unique($baseUnits));

        // Only services (no stock quantity to add up): just the number of items.
        if ($items > 0 && $units === []) {
            return trans_choice('transactions.summary.items', $items, ['count' => self::qty($items)]);
        }

        return trans_choice('transactions.summary.items', $items, ['count' => self::qty($items)])
            .' · '
            .(count($units) === 1 ? self::qtyUnit($total, $units[0]) : trans_choice('transactions.summary.units', (int) ceil($total), ['count' => self::qty($total)]));
    }

    /**
     * "2 items · Net difference −1 Piece", for counts.
     *
     * @param  list<string>  $baseUnits  base unit code of each line
     */
    public static function itemsDifference(int $items, float $difference, array $baseUnits): string
    {
        $units = array_values(array_unique($baseUnits));
        $signed = ($difference > 0 ? '+' : '').self::qty($difference);
        $amount = count($units) === 1
            ? $signed.' '.self::unit($units[0])
            : trans_choice('transactions.summary.units', (int) ceil(abs($difference)), ['count' => $signed]);

        return trans_choice('transactions.summary.items', $items, ['count' => self::qty($items)])
            .' · '
            .__('transactions.summary.net_difference', ['qty' => $amount]);
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date ? Carbon::instance($date)->locale(app()->getLocale())->isoFormat('D MMM YYYY') : '';
    }

    /**
     * A moment (stored in UTC) as the clock showed it in the company's time
     * zone (Settings › General), whatever time zone $date carries.
     */
    public static function dateTime(?DateTimeInterface $date): string
    {
        return $date ? Carbon::instance($date)->setTimezone(CompanyTime::zone())->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm') : '';
    }
}
