<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Support\Format;
use InvalidArgumentException;

/**
 * Converts quantities between one item's units. The base unit is the smallest
 * unit (factor 1) and stock is always counted in it; every other unit converts
 * to more than one base unit (product_unit.factor_to_base).
 *
 *   $units = UnitConverter::forProduct($product);   // or new UnitConverter(['PC' => 1, 'BOX' => 12], 'PC')
 *   $units->toBase(3, 'BOX');          // 36.0
 *   $units->fromBase(64, 'BOX');       // 5.333333
 *   $units->breakdown(64);             // "5 Box + 4 Piece"
 */
final class UnitConverter
{
    /** Quantities are NUMERIC(18,4) and factors NUMERIC(18,6): round to 6 places. */
    private const PRECISION = 6;

    /** @var array<string, float> unit code => factor to base, largest first */
    private array $factors;

    /**
     * @param  array<string, float|int|string>  $factors  unit code => factor to base
     */
    public function __construct(array $factors, private readonly string $base)
    {
        $factors[$base] = 1;

        foreach ($factors as $unit => $factor) {
            if (! is_numeric($factor) || (float) $factor < 1) {
                throw new InvalidArgumentException("Unit [$unit] must convert to at least 1 base unit.");
            }

            $factors[$unit] = (float) $factor;
        }

        arsort($factors);
        $this->factors = $factors;
    }

    public static function forProduct(Product $product): self
    {
        $product->loadMissing(['baseUnit', 'units.unit']);

        $factors = [];
        foreach ($product->units as $productUnit) {
            $factors[$productUnit->unit->code] = $productUnit->factor_to_base;
        }

        return new self($factors, $product->baseUnit->code);
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * @return array<string, float> unit code => factor, largest first
     */
    public function factors(): array
    {
        return $this->factors;
    }

    public function has(string $unit): bool
    {
        return isset($this->factors[$unit]);
    }

    public function factor(string $unit): float
    {
        return $this->factors[$unit] ?? throw new InvalidArgumentException("Unknown unit [$unit].");
    }

    public function toBase(float|int|string $qty, string $unit): float
    {
        return round((float) $qty * $this->factor($unit), self::PRECISION);
    }

    public function fromBase(float|int|string $base, string $unit): float
    {
        return round((float) $base / $this->factor($unit), self::PRECISION);
    }

    /**
     * A base quantity split into whole larger units plus the rest in the base unit,
     * e.g. 64 → [BOX 5, PC 4]. $units limits which units are used (the base unit
     * is always included).
     *
     * @param  list<string>|null  $units
     * @return list<array{unit: string, qty: float}>
     */
    public function parts(float|int|string $base, ?array $units = null): array
    {
        $remaining = abs((float) $base);
        $parts = [];

        foreach ($this->factors as $unit => $factor) {
            if ($unit === $this->base || ($units !== null && ! in_array($unit, $units, true))) {
                continue;
            }

            $whole = floor(round($remaining / $factor, self::PRECISION));

            if ($whole >= 1) {
                $parts[] = ['unit' => $unit, 'qty' => $whole];
                $remaining = round($remaining - $whole * $factor, self::PRECISION);
            }
        }

        if ($remaining > 0 || $parts === []) {
            $parts[] = ['unit' => $this->base, 'qty' => $remaining];
        }

        return $parts;
    }

    /**
     * "5 Box + 4 Piece" (negative quantities get a leading minus).
     *
     * @param  list<string>|null  $units
     */
    public function breakdown(float|int|string $base, ?array $units = null): string
    {
        $text = implode(' + ', array_map(
            fn (array $part) => Format::qtyUnit($part['qty'], $part['unit']),
            $this->parts($base, $units),
        ));

        return (float) $base < 0 ? '-'.$text : $text;
    }
}
