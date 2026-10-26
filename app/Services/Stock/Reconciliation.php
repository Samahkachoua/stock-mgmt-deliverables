<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Checks the balance cache against the ledger (SRS FR-8.3): for every
 * warehouse / customer / item / lot (expiry date + batch), stock_balance.qty_base
 * must equal the sum of its movements (IN minus OUT). Read-only; never repairs
 * anything — a mismatch means something wrote around PostingService and needs
 * a person.
 *
 * It also checks the reserved part: stock_balance.qty_reserved must equal the
 * sum of its stock_reservation rows (rows marked `reserved`).
 */
final class Reconciliation
{
    /**
     * Every key where the two differ, with names for the report.
     *
     * @return list<array{warehouse: string, customer: string, item: string, expiry: string|null, batch: string|null, balance: float, movements: float, reserved?: bool}>
     */
    public function mismatches(): array
    {
        return [...$this->balances(), ...$this->reservations()];
    }

    /**
     * @return list<array{warehouse: string, customer: string, item: string, expiry: string|null, batch: string|null, balance: float, movements: float}>
     */
    private function balances(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH ledger AS (
                SELECT warehouse_id, customer_id, product_id,
                       COALESCE(expiry_date, DATE '0001-01-01') AS expiry_key,
                       COALESCE(batch_no, '') AS batch_key,
                       SUM(CASE WHEN direction = 'IN' THEN qty_base ELSE -qty_base END) AS qty
                  FROM stock_movement
                 GROUP BY 1, 2, 3, 4, 5
            ),
            differences AS (
                SELECT COALESCE(b.warehouse_id, l.warehouse_id) AS warehouse_id,
                       COALESCE(b.customer_id, l.customer_id) AS customer_id,
                       COALESCE(b.product_id, l.product_id) AS product_id,
                       COALESCE(b.expiry_key, l.expiry_key) AS expiry_key,
                       COALESCE(b.batch_key, l.batch_key) AS batch_key,
                       COALESCE(b.qty_base, 0) AS balance,
                       COALESCE(l.qty, 0) AS movements
                  FROM stock_balance b
                  FULL JOIN ledger l
                    ON l.warehouse_id = b.warehouse_id AND l.customer_id = b.customer_id
                   AND l.product_id = b.product_id AND l.expiry_key = b.expiry_key
                   AND l.batch_key = b.batch_key
                 WHERE COALESCE(b.qty_base, 0) <> COALESCE(l.qty, 0)
            )
            SELECT w.code AS warehouse, c.code AS customer, p.sku AS item,
                   NULLIF(d.expiry_key, DATE '0001-01-01') AS expiry, NULLIF(d.batch_key, '') AS batch,
                   d.balance, d.movements
              FROM differences d
              JOIN warehouse w ON w.id = d.warehouse_id
              JOIN customer c ON c.id = d.customer_id
              JOIN product p ON p.id = d.product_id
             ORDER BY w.code, c.code, p.sku, d.expiry_key, d.batch_key
        SQL);

        return array_map(fn (object $row) => [
            'warehouse' => $row->warehouse,
            'customer' => $row->customer,
            'item' => $row->item,
            'expiry' => $row->expiry,
            'batch' => $row->batch,
            'balance' => (float) $row->balance,
            'movements' => (float) $row->movements,
        ], $rows);
    }

    /**
     * Balances whose reserved quantity is not the sum of their reservations
     * (`balance` = qty_reserved, `movements` = the reservations' sum).
     *
     * @return list<array{warehouse: string, customer: string, item: string, expiry: string|null, batch: string|null, balance: float, movements: float, reserved: bool}>
     */
    private function reservations(): array
    {
        $rows = DB::select(<<<'SQL'
            WITH held AS (
                SELECT warehouse_id, customer_id, product_id,
                       COALESCE(expiry_date, DATE '0001-01-01') AS expiry_key,
                       COALESCE(batch_no, '') AS batch_key,
                       SUM(qty_base) AS qty
                  FROM stock_reservation
                 GROUP BY 1, 2, 3, 4, 5
            ),
            differences AS (
                SELECT COALESCE(b.warehouse_id, h.warehouse_id) AS warehouse_id,
                       COALESCE(b.customer_id, h.customer_id) AS customer_id,
                       COALESCE(b.product_id, h.product_id) AS product_id,
                       COALESCE(b.expiry_key, h.expiry_key) AS expiry_key,
                       COALESCE(b.batch_key, h.batch_key) AS batch_key,
                       COALESCE(b.qty_reserved, 0) AS reserved,
                       COALESCE(h.qty, 0) AS held
                  FROM (SELECT * FROM stock_balance WHERE qty_reserved <> 0) b
                  FULL JOIN held h
                    ON h.warehouse_id = b.warehouse_id AND h.customer_id = b.customer_id
                   AND h.product_id = b.product_id AND h.expiry_key = b.expiry_key
                   AND h.batch_key = b.batch_key
                 WHERE COALESCE(b.qty_reserved, 0) <> COALESCE(h.qty, 0)
            )
            SELECT w.code AS warehouse, c.code AS customer, p.sku AS item,
                   NULLIF(d.expiry_key, DATE '0001-01-01') AS expiry, NULLIF(d.batch_key, '') AS batch,
                   d.reserved, d.held
              FROM differences d
              JOIN warehouse w ON w.id = d.warehouse_id
              JOIN customer c ON c.id = d.customer_id
              JOIN product p ON p.id = d.product_id
             ORDER BY w.code, c.code, p.sku, d.expiry_key, d.batch_key
        SQL);

        return array_map(fn (object $row) => [
            'warehouse' => $row->warehouse,
            'customer' => $row->customer,
            'item' => $row->item,
            'expiry' => $row->expiry,
            'batch' => $row->batch,
            'balance' => (float) $row->reserved,
            'movements' => (float) $row->held,
            'reserved' => true,
        ], $rows);
    }
}
