<?php

namespace App\Reports;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Ids;

/**
 * One filter of a report: how it is shown and how a value from the browser
 * (or remembered in the session) is turned into a safe value for the query
 * (anything unknown falls back to the default). The report page and its
 * export use the same normalized values, so they always show the same rows.
 *
 * Types:
 *   search     free text (item name or code, reference...)
 *   customer   one of the customers the user may see, or '' for all
 *   warehouse  one of the user's warehouses (user_warehouse); default the
 *              current one; 'all' = all of them when $allowAll
 *   item       one of the chosen customer's items ('' until chosen)
 *   date       Y-m-d, or '' for no date (the default): see App\Support\DateRange
 *   number     whole number between $min and $max
 *   select     one of $options (value => label key)
 *   toggle     '1' or ''
 */
final class Filter
{
    /**
     * @param  array<string, string>  $options  select: value => translation key
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly string $default = '',
        public readonly array $options = [],
        public readonly bool $allowAll = true,
        public readonly int $min = 0,
        public readonly int $max = 0,
    ) {}

    public static function search(string $label = 'reports.filters.search'): self
    {
        return new self('search', 'search', $label);
    }

    public static function customer(): self
    {
        return new self('customer', 'customer', 'reports.filters.customer');
    }

    public static function warehouse(bool $allowAll = true): self
    {
        return new self('warehouse', 'warehouse', 'reports.filters.warehouse', allowAll: $allowAll);
    }

    public static function item(): self
    {
        return new self('item', 'item', 'reports.filters.item');
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, 'date', $label);
    }

    /**
     * The "Date from" / "Date to" pair every dated report has, empty by default.
     *
     * @return array{0: self, 1: self}
     */
    public static function dates(): array
    {
        return [self::date('from', 'reports.filters.from'), self::date('to', 'reports.filters.to')];
    }

    public static function number(string $name, string $label, int $default, int $min, int $max): self
    {
        return new self($name, 'number', $label, (string) $default, min: $min, max: $max);
    }

    /**
     * @param  array<string, string>  $options  value => translation key; the first is the default
     */
    public static function select(string $name, string $label, array $options): self
    {
        return new self($name, 'select', $label, (string) array_key_first($options), $options);
    }

    public static function toggle(string $name, string $label): self
    {
        return new self($name, 'toggle', $label);
    }

    /**
     * A safe value for the query. $previous holds the filters normalized so far
     * (the item filter needs the customer).
     *
     * @param  array<string, string>  $previous
     */
    public function normalize(mixed $value, User $user, array $previous): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return match ($this->type) {
            'search' => mb_substr($value, 0, 100),
            'customer' => $value !== '' && Ids::valid($value) && Customer::whereKey($value)->exists() ? $value : '',
            'warehouse' => match (true) {
                $this->allowAll && $value === 'all' => 'all',
                $value !== '' && $user->canAccessWarehouse($value) => $value,
                default => (string) (session(EnsureCurrentWarehouse::SESSION_KEY) ?: ($user->warehouseIds()[0] ?? '')),
            },
            'item' => $value !== '' && Ids::valid($value) && ($previous['customer'] ?? '') !== ''
                && Product::whereKey($value)->where('customer_id', $previous['customer'])->exists() ? $value : '',
            'date' => DateRange::day($value),
            'number' => ctype_digit($value) && (int) $value >= $this->min && (int) $value <= $this->max ? (string) (int) $value : $this->default,
            'select' => array_key_exists($value, $this->options) ? $value : $this->default,
            'toggle' => $value === '1' ? '1' : '',
            default => '',
        };
    }

    /**
     * Options to show for customer / warehouse / item / select filters, value => label.
     *
     * @param  array<string, string>  $filters  normalized
     * @return array<string, string>
     */
    public function choices(User $user, array $filters): array
    {
        return match ($this->type) {
            'customer' => ['' => __('reports.filters.all_customers')] + Customer::orderBy('name')->pluck('name', 'id')->all(),
            'warehouse' => ($this->allowAll ? ['all' => __('reports.filters.all_warehouses')] : [])
                + $user->assignedWarehouses()->pluck('name', 'id')->all(),
            'item' => ['' => __('reports.filters.choose_item')] + (($filters['customer'] ?? '') === '' ? [] : Product::query()
                ->where('customer_id', $filters['customer'])
                ->orderBy('name_en')
                ->get()
                ->mapWithKeys(fn (Product $product) => [$product->id => $product->sku.' · '.$product->name()])
                ->all()),
            'select' => array_map(fn (string $key) => __($key), $this->options),
            default => [],
        };
    }
}
