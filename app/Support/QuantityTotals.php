<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Total quantity" on the Delivery note and Order lists: the stock lines of
 * a document (services left out) added up in base units, "212 Piece", or
 * "250 units" when the items have different base units. Per row (column)
 * and for every document matching the list's filters (footer).
 */
final class QuantityTotals
{
    /**
     * Delivery note lines: what was delivered once delivered, what was ordered before.
     */
    public const DELIVERED_OR_ORDERED = 'coalesce(ql.qty_received, ql.qty_base)';

    public const ORDERED = 'ql.qty_base';

    /**
     * Subquery for one document's total ("212.0000|PC"), for selectSub().
     *
     * @param  string  $lineTable  stock_transaction_line or stock_draft_line
     * @param  string  $foreignKey  its column pointing at the document
     * @param  string  $documentId  the document id column of the outer query
     */
    public static function perDocument(string $lineTable, string $foreignKey, string $documentId, string $quantity): Builder
    {
        return self::lines($lineTable, $quantity)->whereColumn("ql.{$foreignKey}", $documentId);
    }

    /**
     * The total of all documents whose ids the query selects; null with no stock lines.
     */
    public static function ofDocuments(string $lineTable, string $foreignKey, BuilderContract $documentIds, string $quantity): ?string
    {
        return self::format(self::lines($lineTable, $quantity)->whereIn("ql.{$foreignKey}", $documentIds)->value('total'));
    }

    /**
     * "212 Piece" from "212.0000|PC"; null for a document with only services.
     */
    public static function format(mixed $value): ?string
    {
        if (! is_string($value) || ! str_contains($value, '|')) {
            return null;
        }

        [$total, $units] = explode('|', $value, 2);

        return Format::quantityTotal((float) $total, explode(',', $units));
    }

    private static function lines(string $lineTable, string $quantity): Builder
    {
        return DB::table("{$lineTable} as ql")
            ->join('product as qp', 'qp.id', '=', 'ql.product_id')
            ->join('unit as qu', 'qu.id', '=', 'qp.base_unit_id')
            ->where('qp.is_service', false)
            ->selectRaw("sum({$quantity})::text || '|' || string_agg(DISTINCT qu.code, ',') as total");
    }
}
