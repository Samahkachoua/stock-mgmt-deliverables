<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No batch numbers: stock of an expiry item is kept per expiry date only.
     * The batch table goes; lines, movements, balances and in-transit rows
     * carry expiry_date themselves (NULL for items that don't track expiry).
     * As before, a generated key collapses NULL to a fixed sentinel so the
     * primary keys still allow one row per item and expiry-or-none.
     *
     * Existing rows take the expiry date of their batch. Two batches of one
     * item with the same expiry date would collide, so that stops the
     * migration (only local/test data existed when this was written).
     * The posting functions get an expiry date instead of a batch id.
     * No new table, so nothing to add to row-level security.
     *
     * schema.sql already has this shape, so a database created from it has no
     * batch table and there is nothing to do; only older databases change.
     */
    public function up(): void
    {
        if (! Schema::hasTable('batch')) {
            return;
        }

        $clash = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM (
                SELECT 1 FROM batch GROUP BY product_id, expiry_date HAVING count(*) > 1
                UNION ALL
                SELECT 1 FROM batch WHERE expiry_date IS NULL
            ) c
        SQL);

        if ((int) $clash->n > 0) {
            throw new RuntimeException('Batches share an item and expiry date, or have no expiry date. Recreate the local database: php artisan migrate:fresh --seed');
        }

        foreach (['stock_transaction_line', 'stock_movement', 'stock_balance', 'in_transit_balance'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN expiry_date DATE");
            DB::statement("UPDATE {$table} t SET expiry_date = b.expiry_date FROM batch b WHERE b.id = t.batch_id");
        }

        DB::statement('DROP INDEX IF EXISTS idx_movement_lookup');
        DB::statement('CREATE INDEX idx_movement_lookup ON stock_movement (warehouse_id, customer_id, product_id, expiry_date)');

        DB::statement('ALTER TABLE stock_balance DROP CONSTRAINT stock_balance_pkey');
        DB::statement('ALTER TABLE stock_balance DROP COLUMN batch_key');
        DB::statement("ALTER TABLE stock_balance ADD COLUMN expiry_key DATE GENERATED ALWAYS AS (COALESCE(expiry_date, DATE '0001-01-01')) STORED");
        DB::statement('ALTER TABLE stock_balance ADD PRIMARY KEY (warehouse_id, customer_id, product_id, expiry_key)');

        DB::statement('ALTER TABLE in_transit_balance DROP CONSTRAINT in_transit_balance_pkey');
        DB::statement('ALTER TABLE in_transit_balance DROP COLUMN batch_key');
        DB::statement("ALTER TABLE in_transit_balance ADD COLUMN expiry_key DATE GENERATED ALWAYS AS (COALESCE(expiry_date, DATE '0001-01-01')) STORED");
        DB::statement('ALTER TABLE in_transit_balance ADD PRIMARY KEY (transaction_id, product_id, expiry_key)');

        foreach (['stock_transaction_line', 'stock_movement', 'stock_balance', 'in_transit_balance'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN batch_id");
        }

        DB::statement('DROP TABLE batch');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION fn_post_movement(BIGINT, BIGINT, BIGINT, BIGINT, BIGINT, BIGINT, movement_direction, NUMERIC);
            DROP FUNCTION fn_apply_balance(BIGINT, BIGINT, BIGINT, BIGINT, movement_direction, NUMERIC);

            -- Applies one movement to stock_balance atomically, blocking negative stock.
            CREATE FUNCTION fn_apply_balance(
                p_warehouse_id BIGINT,
                p_customer_id  BIGINT,
                p_product_id   BIGINT,
                p_expiry_date  DATE,
                p_direction    movement_direction,
                p_qty_base     NUMERIC
            ) RETURNS VOID AS $$
            DECLARE
                v_current NUMERIC(18,4);
                v_delta   NUMERIC(18,4);
            BEGIN
                v_delta := CASE WHEN p_direction = 'IN' THEN p_qty_base ELSE -p_qty_base END;

                -- Lock the balance row (or create it) before checking/adjusting, to prevent
                -- two concurrent postings from both passing the negative-stock check.
                -- ON CONFLICT targets the generated expiry_key column via the primary key.
                INSERT INTO stock_balance (warehouse_id, customer_id, product_id, expiry_date, qty_base)
                VALUES (p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, 0)
                ON CONFLICT (warehouse_id, customer_id, product_id, expiry_key) DO NOTHING;

                SELECT qty_base INTO v_current
                FROM stock_balance
                WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
                  AND product_id = p_product_id
                  AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
                FOR UPDATE;

                IF v_current + v_delta < 0 THEN
                    RAISE EXCEPTION 'Negative stock blocked: warehouse=%, customer=%, product=%, expiry=%, available=%, requested_out=%',
                        p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, v_current, p_qty_base;
                END IF;

                UPDATE stock_balance
                   SET qty_base = v_current + v_delta, updated_at = now()
                 WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
                   AND product_id = p_product_id
                   AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01');
            END;
            $$ LANGUAGE plpgsql;

            -- Writes one immutable stock_movement and updates stock_balance.
            -- p_expiry_date is NULL for items that don't track expiry.
            CREATE FUNCTION fn_post_movement(
                p_transaction_id BIGINT,
                p_line_id      BIGINT,
                p_warehouse_id BIGINT,
                p_customer_id  BIGINT,
                p_product_id   BIGINT,
                p_expiry_date  DATE,
                p_direction    movement_direction,
                p_qty_base     NUMERIC
            ) RETURNS BIGINT AS $$
            DECLARE
                v_movement_id BIGINT;
            BEGIN
                PERFORM fn_apply_balance(p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_direction, p_qty_base);

                INSERT INTO stock_movement (transaction_id, line_id, warehouse_id, customer_id, product_id, expiry_date, direction, qty_base)
                VALUES (p_transaction_id, p_line_id, p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_direction, p_qty_base)
                RETURNING id INTO v_movement_id;

                RETURN v_movement_id;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Batches were removed for good; recreate the database instead (php artisan migrate:fresh --seed).');
    }
};
