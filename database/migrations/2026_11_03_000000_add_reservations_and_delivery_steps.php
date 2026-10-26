<?php

use App\Support\Transactions\TransactionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reserved stock and the three steps of a Delivery note.
     *
     * - An Order reserves what it takes: stock_reservation holds one row per
     *   order line (later: delivery note line) and lot, or per serial, and
     *   stock_balance.qty_reserved caches their sum. Both are written only by
     *   fn_reserve / fn_release_reservations. Available = qty_base - qty_reserved.
     * - fn_apply_balance refuses taking reserved stock ("Reserved stock blocked"),
     *   so a Damage, Transfer, count or cancelled receipt can't take it.
     * - transaction_type.delivery_steps (Delivery note): confirming an Order
     *   creates it CONFIRMED (no stock change, the reservation moves to it), then
     *   DISPATCHED (driver, phone, truck), then POSTED (delivered: the delivered
     *   quantities leave stock, the rest is released). qty_received / shortage_reason
     *   on its lines hold what was delivered and why less; a line delivered
     *   instead of another points at it (replaces_line_id).
     * - stock.out.create now means dispatching and delivering: nobody creates a
     *   Delivery note by hand any more, it always comes from an Order.
     * - Orders still open are reserved now (oldest first); one that no longer
     *   fits is left unreserved and named in the output.
     *
     * Same as schema.sql; a database created from it already has the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_reservation')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE transaction_type ADD COLUMN delivery_steps BOOLEAN NOT NULL DEFAULT FALSE;
            UPDATE transaction_type SET delivery_steps = TRUE WHERE code = 'OUT';

            INSERT INTO transaction_status (code, name, is_terminal, affects_stock, display_order)
            VALUES ('CONFIRMED', 'Confirmed', FALSE, FALSE, 9);

            ALTER TABLE stock_transaction
                ADD COLUMN driver_name  VARCHAR(100),
                ADD COLUMN driver_phone VARCHAR(30),
                ADD COLUMN truck_no     VARCHAR(30),
                ADD COLUMN delivered_at TIMESTAMPTZ,
                ADD COLUMN delivered_by BIGINT REFERENCES app_user(id);

            ALTER TABLE stock_transaction_line
                ADD COLUMN replaces_line_id BIGINT REFERENCES stock_transaction_line(id),
                DROP CONSTRAINT chk_shortage_reason,
                ADD CONSTRAINT chk_shortage_reason CHECK (shortage_reason IS NULL OR shortage_reason IN (
                    'DAMAGED_IN_TRANSIT', 'MISSING', 'COUNTING_ERROR',
                    'NOT_AVAILABLE', 'REFUSED', 'DAMAGED', 'REPLACED'));

            -- Delivery notes posted before the steps: everything was delivered.
            UPDATE stock_transaction_line l SET qty_received = l.qty_base
              FROM stock_transaction t
             WHERE t.id = l.transaction_id AND t.transaction_type = 'OUT' AND l.qty_received IS NULL;

            ALTER TABLE stock_balance
                ADD COLUMN qty_reserved NUMERIC(18,4) NOT NULL DEFAULT 0,
                ADD CONSTRAINT ck_stock_balance_reserved CHECK (qty_reserved >= 0 AND qty_reserved <= qty_base);

            CREATE TABLE stock_reservation (
                id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                draft_line_id BIGINT REFERENCES stock_draft_line(id),
                line_id       BIGINT REFERENCES stock_transaction_line(id),
                warehouse_id  BIGINT NOT NULL REFERENCES warehouse(id),
                customer_id   BIGINT NOT NULL REFERENCES customer(id),
                product_id    BIGINT NOT NULL REFERENCES product(id),
                expiry_date   DATE,
                batch_no      VARCHAR(40),
                serial_id     BIGINT REFERENCES serial_number(id),
                qty_base      NUMERIC(18,4) NOT NULL CHECK (qty_base > 0),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT ck_stock_reservation_holder CHECK ((draft_line_id IS NULL) <> (line_id IS NULL))
            );

            CREATE INDEX idx_reservation_draft_line ON stock_reservation (draft_line_id);
            CREATE INDEX idx_reservation_line ON stock_reservation (line_id);
            CREATE INDEX idx_reservation_balance ON stock_reservation (warehouse_id, customer_id, product_id);
            CREATE UNIQUE INDEX uq_reservation_serial ON stock_reservation (serial_id) WHERE serial_id IS NOT NULL;

            ALTER TABLE stock_reservation ENABLE ROW LEVEL SECURITY;

            UPDATE permission SET description = 'Dispatch delivery notes and confirm delivery (they are created from orders)'
             WHERE code = 'stock.out.create';
            SQL);

        DB::unprepared(self::FUNCTIONS);

        // Orders still open: reserve them, oldest first.
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
                v_draft RECORD;
                v_line  RECORD;
            BEGIN
                FOR v_draft IN SELECT id, doc_no, warehouse_id, customer_id FROM stock_draft
                                WHERE transaction_type = 'OUT' AND status = 'DRAFT' ORDER BY created_at, id LOOP
                    BEGIN
                        FOR v_line IN SELECT l.* FROM stock_draft_line l JOIN product p ON p.id = l.product_id
                                       WHERE l.draft_id = v_draft.id AND NOT p.is_service ORDER BY l.line_no LOOP
                            IF jsonb_array_length(v_line.serials) > 0 THEN
                                PERFORM fn_reserve(v_line.id, NULL, v_draft.warehouse_id, v_draft.customer_id, v_line.product_id, NULL, NULL,
                                    ARRAY(SELECT s.id FROM serial_number s
                                           WHERE s.product_id = v_line.product_id
                                             AND s.serial_no IN (SELECT jsonb_array_elements_text(v_line.serials))),
                                    v_line.qty_base);
                            ELSE
                                PERFORM fn_reserve(v_line.id, NULL, v_draft.warehouse_id, v_draft.customer_id, v_line.product_id,
                                    v_line.expiry_date, v_line.batch_no, NULL, v_line.qty_base);
                            END IF;
                        END LOOP;
                    EXCEPTION WHEN OTHERS THEN
                        RAISE NOTICE 'Order % could not be reserved (not enough stock): edit it before confirming', v_draft.doc_no;
                    END;
                END LOOP;
            END $$;
            SQL);

        // The types are cached (rule 4): without this, the app keeps the rows
        // from before delivery_steps existed and Delivery notes / Orders fail.
        TransactionTypes::flush();
    }

    public function down(): void
    {
        throw new RuntimeException('Reservations cannot be removed by a rollback.');
    }

    /** The balance and reservation functions, the same as in schema.sql. */
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
            v_current  NUMERIC(18,4);
            v_reserved NUMERIC(18,4);
            v_delta    NUMERIC(18,4);
        BEGIN
            v_delta := CASE WHEN p_direction = 'IN' THEN p_qty_base ELSE -p_qty_base END;

            INSERT INTO stock_balance (warehouse_id, customer_id, product_id, expiry_date, batch_no, qty_base)
            VALUES (p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, 0)
            ON CONFLICT (warehouse_id, customer_id, product_id, expiry_key, batch_key) DO NOTHING;

            SELECT qty_base, qty_reserved INTO v_current, v_reserved
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

            -- Stock reserved for orders / delivery notes can only leave once released.
            IF v_delta < 0 AND v_current + v_delta < v_reserved THEN
                RAISE EXCEPTION 'Reserved stock blocked: warehouse=%, customer=%, product=%, expiry=%, batch=%, available=%, requested_out=%',
                    p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, v_current - v_reserved, p_qty_base;
            END IF;

            UPDATE stock_balance
               SET qty_base = v_current + v_delta, updated_at = now()
             WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
               AND product_id = p_product_id
               AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
               AND batch_key = COALESCE(p_batch_no, '');
        END;
        $$ LANGUAGE plpgsql;

        -- Reserves stock for an order line (p_draft_line_id) or a delivery note line
        -- (p_line_id): rows in stock_reservation (one, or one per serial in
        -- p_serial_ids) and qty_reserved on the balance, locked, never more than what
        -- is not reserved yet. Called only through App\Services\Stock\Reservations.
        CREATE OR REPLACE FUNCTION fn_reserve(
            p_draft_line_id BIGINT,
            p_line_id       BIGINT,
            p_warehouse_id  BIGINT,
            p_customer_id   BIGINT,
            p_product_id    BIGINT,
            p_expiry_date   DATE,
            p_batch_no      VARCHAR,
            p_serial_ids    BIGINT[],
            p_qty_base      NUMERIC
        ) RETURNS VOID AS $$
        DECLARE
            v_current  NUMERIC(18,4);
            v_reserved NUMERIC(18,4);
        BEGIN
            SELECT qty_base, qty_reserved INTO v_current, v_reserved
            FROM stock_balance
            WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
              AND product_id = p_product_id
              AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
              AND batch_key = COALESCE(p_batch_no, '')
            FOR UPDATE;

            IF NOT FOUND OR v_reserved + p_qty_base > v_current THEN
                RAISE EXCEPTION 'Reserved stock exceeded: warehouse=%, customer=%, product=%, expiry=%, batch=%, available=%, requested=%',
                    p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, COALESCE(v_current - v_reserved, 0), p_qty_base;
            END IF;

            UPDATE stock_balance
               SET qty_reserved = v_reserved + p_qty_base, updated_at = now()
             WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
               AND product_id = p_product_id
               AND expiry_key = COALESCE(p_expiry_date, DATE '0001-01-01')
               AND batch_key = COALESCE(p_batch_no, '');

            IF p_serial_ids IS NULL OR cardinality(p_serial_ids) = 0 THEN
                INSERT INTO stock_reservation (draft_line_id, line_id, warehouse_id, customer_id, product_id, expiry_date, batch_no, qty_base)
                VALUES (p_draft_line_id, p_line_id, p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, p_qty_base);
            ELSE
                IF cardinality(p_serial_ids) <> p_qty_base THEN
                    RAISE EXCEPTION 'One reservation per serial: % serials for %', cardinality(p_serial_ids), p_qty_base;
                END IF;

                INSERT INTO stock_reservation (draft_line_id, line_id, warehouse_id, customer_id, product_id, expiry_date, batch_no, serial_id, qty_base)
                SELECT p_draft_line_id, p_line_id, p_warehouse_id, p_customer_id, p_product_id, p_expiry_date, p_batch_no, s, 1
                  FROM unnest(p_serial_ids) AS s;
            END IF;
        END;
        $$ LANGUAGE plpgsql;

        -- Releases reservations: the rows go, qty_reserved goes down by their
        -- quantities. Balances are locked in key order first, so two releases never
        -- wait for each other in opposite orders.
        CREATE OR REPLACE FUNCTION fn_release_reservations(p_ids BIGINT[]) RETURNS VOID AS $$
        BEGIN
            PERFORM 1
               FROM stock_balance b
               JOIN (SELECT DISTINCT warehouse_id, customer_id, product_id,
                            COALESCE(expiry_date, DATE '0001-01-01') AS expiry_key, COALESCE(batch_no, '') AS batch_key
                       FROM stock_reservation WHERE id = ANY (p_ids)) k
                 ON k.warehouse_id = b.warehouse_id AND k.customer_id = b.customer_id AND k.product_id = b.product_id
                AND k.expiry_key = b.expiry_key AND k.batch_key = b.batch_key
              ORDER BY b.warehouse_id, b.customer_id, b.product_id, b.expiry_key, b.batch_key
                FOR UPDATE OF b;

            WITH gone AS (
                DELETE FROM stock_reservation WHERE id = ANY (p_ids) RETURNING *
            ), released AS (
                SELECT warehouse_id, customer_id, product_id,
                       COALESCE(expiry_date, DATE '0001-01-01') AS expiry_key, COALESCE(batch_no, '') AS batch_key,
                       SUM(qty_base) AS qty
                  FROM gone
                 GROUP BY 1, 2, 3, 4, 5
            )
            UPDATE stock_balance b
               SET qty_reserved = b.qty_reserved - r.qty, updated_at = now()
              FROM released r
             WHERE b.warehouse_id = r.warehouse_id AND b.customer_id = r.customer_id AND b.product_id = r.product_id
               AND b.expiry_key = r.expiry_key AND b.batch_key = r.batch_key;
        END;
        $$ LANGUAGE plpgsql;
        SQL;
};
