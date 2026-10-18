<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * The "Date from" / "Date to" pair of every list and report (CLAUDE.md "List
 * state"): both empty by default (no date filter), both days included.
 *
 *   only from  from that day onwards
 *   only to    up to and including that day
 *   both       between them
 *
 * A "from" after the "to" is invalid(): the list shows the error and runs no query.
 */
final class DateRange
{
    /** Y-m-d, or '' for no limit. */
    public readonly string $from;

    /** Y-m-d, or '' for no limit. */
    public readonly string $to;

    public function __construct(mixed $from = '', mixed $to = '')
    {
        $this->from = self::day($from);
        $this->to = self::day($to);
    }

    /**
     * A day from the browser or the session as Y-m-d; anything else is '' (no date).
     */
    public static function day(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    public function isEmpty(): bool
    {
        return $this->from === '' && $this->to === '';
    }

    /**
     * "Date from" is after "Date to".
     */
    public function invalid(): bool
    {
        return $this->from !== '' && $this->to !== '' && $this->from > $this->to;
    }

    /**
     * Limits a DATE column (a document's own date).
     *
     * @template TQuery of EloquentBuilder<*>|Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function whereDay(EloquentBuilder|Builder $query, string $column): EloquentBuilder|Builder
    {
        if ($this->from !== '') {
            $query->where($column, '>=', $this->from);
        }

        if ($this->to !== '') {
            $query->where($column, '<=', $this->to);
        }

        return $query;
    }

    /**
     * Limits a TIMESTAMPTZ column (stored in UTC) to the days as they are in
     * $timezone: "Date to" runs until the end of that day there.
     *
     * @template TQuery of EloquentBuilder<*>|Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function whereInstant(EloquentBuilder|Builder $query, string $column, string $timezone): EloquentBuilder|Builder
    {
        if (($start = $this->start($timezone)) !== null) {
            $query->where($column, '>=', $start);
        }

        if (($end = $this->end($timezone)) !== null) {
            $query->where($column, '<', $end);
        }

        return $query;
    }

    /**
     * Start of the first day in $timezone, as a UTC instant; null without "Date from".
     */
    public function start(string $timezone): ?CarbonImmutable
    {
        return $this->from === '' ? null : CarbonImmutable::parse($this->from, $timezone)->startOfDay()->utc();
    }

    /**
     * Start of the day after the last in $timezone (exclusive), as a UTC instant; null without "Date to".
     */
    public function end(string $timezone): ?CarbonImmutable
    {
        return $this->to === '' ? null : CarbonImmutable::parse($this->to, $timezone)->addDay()->startOfDay()->utc();
    }
}
