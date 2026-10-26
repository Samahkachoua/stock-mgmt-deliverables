<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch numbers for expiry items: stock is kept per expiry date AND batch.
     *
     * - product_batch: one row per item + batch number, with its one expiry date
     *   (a batch never has two dates). Lines and movements point at it through
     *   (product_id, batch_no, expiry_date), so the database refuses a batch with
     *   another date. Only items that track expiry have batches.
     * - batch_no on lines, draft lines, movements, balances and in-transit rows;
     *   balances and in-transit rows key on it ('' = no batch).
     * - The posting functions take the batch.
     *
     * Stock that exists already keeps no batch ("No batch"): nothing is lost and
     * it can still go out or be counted.
     *
     * Same as schema.sql; a database created from it already has the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_batch')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE product_batch (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                product_id  BIGINT NOT NULL REFERENCES product(id),
                batch_no    VARCHAR(40) NOT NULL CHECK (batch_no <> '' AND batch_no = upper(btrim(batch_no))),
                expiry_date DATE NOT NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_product_batch UNIQUE (product_id, batch_no),
                CONSTRAINT uq_product_batch_expiry UNIQUE (product_id, batch_no, expiry_date)
            );

            ALTER TABLE product_batch ENABLE ROW LEVEL SECURITY;

            CREATE OR REPLACE FUNCTION fn_validate_product_batch() RETURNS TRIGGER AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM product WHERE id = NEW.product_id AND track_expiry) THEN
                    RAISE EXCEPTION 'Only expiry-tracked products have batches (product_id=%)', NEW.product_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_validate_product_batch
                BEFORE INSERT OR UPDATE ON product_batch
                FOR EACH ROW EXECUTE FUNCTION fn_validate_product_batch();

            ALTER TABLE stock_transaction_line ADD COLUMN batch_no VARCHAR(40),
                ADD CONSTRAINT fk_transaction_line_batch FOREIGN KEY (product_id, batch_no, expiry_date)
                    REFERENCES product_batch (product_id, batch_no, expiry_date);
            ALTER TABLE stock_draft_line ADD COLUMN batch_no VARCHAR(40);
            ALTER TABLE stock_movement ADD COLUMN batch_no VARCHAR(40),
                ADD CONSTRAINT fk_movement_batch FOREIGN KEY (product_id, batch_no, expiry_date)
                    REFERENCES product_batch (product_id, batch_no, expiry_date);

            DROP INDEX uq_transaction_line_key;
            CREATE UNIQUE INDEX uq_transaction_line_key
                ON stock_transaction_line (transaction_id, product_id, unit_id, COALESCE(expiry_date, DATE '0001-01-01'), COALESCE(batch_no, ''));

            DROP INDEX idx_movement_lookup;
            CREATE INDEX idx_movement_lookup ON stock_movement (warehouse_id, customer_id, product_id, expiry_date, batch_no);

            ALTER TABLE stock_balance ADD COLUMN batch_no VARCHAR(40),
                ADD COLUMN batch_key VARCHAR(40) GENERATED ALWAYS AS (COALESCE(batch_no, '')) STORED;
            ALTER TABLE stock_balance DROP CONSTRAINT stock_balance_pkey;
            ALTER TABLE stock_balance ADD PRIMARY KEY (warehouse_id, customer_id, product_id, expiry_key, batch_key);

            ALTER TABLE in_transit_balance ADD COLUMN batch_no VARCHAR(40),
                ADD COLUMN batch_key VARCHAR(40) GENERATED ALWAYS AS (COALESCE(batch_no, '')) STORED;
            ALTER TABLE in_transit_balance DROP CONSTRAINT in_transit_balance_pkey;
            ALTER TABLE in_transit_balance ADD PRIMARY KEY (transaction_id, product_id, expiry_key, batch_key);

            DROP FUNCTION fn_post_movement(BIGINT, BIGINT, BIGINT, BIGINT, BIGINT, DATE, movement_direction, NUMERIC);
            DROP FUNCTION fn_apply_balance(BIGINT, BIGINT, BIGINT, DATE, movement_direction, NUMERIC);
            SQL);

        DB::unprepared(self::FUNCTIONS);
    }

    public function down(): void
    {
        // Batches can't be merged back without losing which stock is which.
        throw new RuntimeException('Batch numbers cannot be removed by a rollback.');
    }

    /** The posting functions, the same as in schema.sql. */
    private const FUNCTIONS = <<<'SQL'
        CREATE OR REPLACE FUNCTION fn_apply_balance(
            p_warehouse_id BIGINT,
            p_customer_id  BIGINT,
            p_product_id   BIGINT,
            p_expiry_date  DATE,
            p_batch_no     VARCHAR,
            p_direction    movement_direction,
            p_qty_base     NUMERIC
        ) RETURNS VOID AS $$
        DECLARE
            v_current NUMERIC(18,4);
            v_delta   NUMERIC(18,4);
        BEGIN
            v_delta := CASE WHEN p_direction = 'IN' THEN p_qty_base ELSE -p_qty_base END;

            INSERT INTO stock_balance (warehouse_id, customer_id, product_id, expiry_date, batch_no, qty_base)
            VALUES (p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, 0)
            ON CONFLICT (warehouse_id, customer_id, product_id, expiry_key, batch_key) DO NOTHING;

            SELECT qty_base INTO v_current
            FROM stock_balance
            WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
              AND product_id = p_product_id
              AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
              AND batch_key = COALESCE(p_batch_no, '')
            FOR UPDATE;

            IF v_current + v_delta < 0 THEN
                RAISE EXCEPTION 'Negative stock blocked: warehouse=%, customer=%, product=%, expiry=%, batch=%, available=%, requested_out=%',
                    p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, v_current, p_qty_base;
            END IF;

            UPDATE stock_balance
               SET qty_base = v_current + v_delta, updated_at = now()
             WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
               AND product_id = p_product_id
               AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
               AND batch_key = COALESCE(p_batch_no, '');
        END;
        $$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION fn_post_movement(
            p_transaction_id BIGINT,
            p_line_id     BIGINT,
            p_warehouse_id BIGINT,
            p_customer_id  BIGINT,
            p_product_id   BIGINT,
            p_expiry_date  DATE,
            p_batch_no     VARCHAR,
            p_direction    movement_direction,
            p_qty_base     NUMERIC
        ) RETURNS BIGINT AS $$
        DECLARE
            v_movement_id BIGINT;
        BEGIN
            IF EXISTS (SELECT 1 FROM product WHERE id = p_product_id AND is_service) THEN
                RAISE EXCEPTION 'Service item has no stock (product_id=%)', p_product_id;
            END IF;

            PERFORM fn_apply_balance(p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, p_direction, p_qty_base);

            INSERT INTO stock_movement (transaction_id, line_id, warehouse_id, customer_id, product_id, expiry_date, batch_no, direction, qty_base)
            VALUES (p_transaction_id, p_line_id, p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, p_direction, p_qty_base)
            RETURNING id INTO v_movement_id;

            RETURN v_movement_id;
        END;
        $$ LANGUAGE plpgsql;
        SQL;
};
