<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Batch numbers of expiry items (CLAUDE.md rule 9): stock is kept per expiry
 * date and batch. A batch has one expiry date per item (product_batch).
 *
 * A "lot" is one expiry date + batch of an item. Forms pass a picked lot as one
 * value, key() ("2027-03-12|B-1042"; "2027-03-12|" is stock without a batch).
 */
final class Batches
{
    /** Longest batch number (product_batch.batch_no). */
    public const MAX = 40;

    /**
     * How a typed batch number is kept: trimmed, single spaces, upper case.
     */
    public static function normalize(?string $batch): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim((string) $batch)));
    }

    /**
     * One value for an expiry date and batch ('' or null = no batch).
     */
    public static function key(?string $expiry, ?string $batch): string
    {
        return (string) $expiry.'|'.(string) $batch;
    }

    /**
     * The expiry date and batch of a key() ('' = no batch); [value, null] for a
     * value without "|" (just an expiry date: any batch of it).
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split(string $key): array
    {
        if (! str_contains($key, '|')) {
            return [$key, null];
        }

        [$expiry, $batch] = explode('|', $key, 2);

        return [$expiry, $batch];
    }

    /**
     * The expiry date of each known batch of an item, batch => Y-m-d.
     *
     * @param  list<string>  $batches
     * @return array<string, string>
     */
    public static function dates(string $productId, array $batches): array
    {
        $batches = array_values(array_filter(array_map(self::normalize(...), $batches), fn (string $batch) => $batch !== ''));

        return $batches === [] ? [] : DB::table('product_batch')
            ->where('product_id', $productId)
            ->whereIn('batch_no', $batches)
            ->pluck('expiry_date', 'batch_no')
            ->all();
    }

    /**
     * "B-1042 · 12 Mar 2027", or just "12 Mar 2027" for stock without a batch.
     */
    public static function text(?string $expiry, ?string $batch): string
    {
        return filled($batch) ? self::label($expiry, $batch) : ($expiry === null ? '' : Format::date(CarbonImmutable::parse($expiry)));
    }

    /**
     * "B-1042 · 12 Mar 2027", or "No batch · 12 Mar 2027".
     */
    public static function label(?string $expiry, ?string $batch): string
    {
        $date = $expiry === null ? '' : Format::date(CarbonImmutable::parse($expiry));

        return __('stock.batch.label', ['batch' => filled($batch) ? $batch : __('stock.batch.none'), 'date' => $date]);
    }
}
