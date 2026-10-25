<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use App\Services\Stock\UnitConverter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * An item owned by a customer (stock is never owned by the 3PL itself).
 * The code lives in `sku`; names exist per language (name_en is required).
 *
 * @property string $id
 * @property string $customer_id
 * @property string $sku
 * @property string $name_en
 * @property string|null $name_ar
 * @property string|null $name_fr
 * @property string $base_unit_id
 * @property bool $track_expiry
 * @property bool $track_serial
 * @property bool $is_service a service (packing, delivery fee...): on receipts / delivery notes, never stock
 * @property string|null $storage_status storage_status.code; null = not set
 * @property string|null $approval_threshold_qty
 * @property bool $active
 * @property-read Customer $customer
 * @property-read Unit $baseUnit
 * @property-read Collection<int, ProductUnit> $units
 */
class Product extends Model
{
    use CustomerScoped, HasNumericKey;

    protected $table = 'product';

    /**
     * The column's default, so a model created without it says so too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_service' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'sku',
        'name_en',
        'name_ar',
        'name_fr',
        'base_unit_id',
        'track_expiry',
        'track_serial',
        'is_service',
        'storage_status',
        'approval_threshold_qty',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_expiry' => 'boolean',
            'track_serial' => 'boolean',
            'is_service' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * Every unit the item is handled in, including the base unit's own row (factor 1).
     *
     * @return HasMany<ProductUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /**
     * Name in the current language, falling back to English.
     */
    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = $this->getAttribute('name_'.$locale);

        return filled($name) ? $name : $this->name_en;
    }

    public function converter(): UnitConverter
    {
        return UnitConverter::forProduct($this);
    }

    /**
     * Once stock has moved, the base unit and tracking flags are fixed: changing
     * them would change the meaning of quantities already in the ledger.
     */
    public function hasMovements(): bool
    {
        return $this->exists && DB::table('stock_movement')->where('product_id', $this->id)->exists();
    }

    /**
     * Is the item on any document or draft? A service has no movements, so
     * this (not hasMovements()) is what fixes whether an item is a service.
     */
    public function hasLines(): bool
    {
        return $this->exists && (DB::table('stock_transaction_line')->where('product_id', $this->id)->exists()
            || DB::table('stock_draft_line')->where('product_id', $this->id)->exists());
    }

    /**
     * Items kept in stock (not services).
     *
     * @param  Builder<Product>  $query
     */
    public function scopeStocked(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_service'), false);
    }

    /**
     * Code or name (any language) contains the text, case-insensitively.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($text))).'%';

        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(sku) like ?', [$like])
            ->orWhereRaw('lower(name_en) like ?', [$like])
            ->orWhereRaw('lower(name_ar) like ?', [$like])
            ->orWhereRaw('lower(name_fr) like ?', [$like]));
    }
}
