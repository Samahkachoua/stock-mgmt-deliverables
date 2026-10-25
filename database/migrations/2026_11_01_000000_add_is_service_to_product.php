<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Service items (packing, delivery fee...): a line on receipts and
     * delivery notes that never touches stock. A service has no expiry, no
     * serials and only its base unit, and fn_post_movement refuses it.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('product', 'is_service')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE product
                ADD COLUMN is_service BOOLEAN NOT NULL DEFAULT FALSE,
                ADD CONSTRAINT ck_product_service_untracked
                    CHECK (NOT is_service OR (NOT track_expiry AND NOT track_serial));

            CREATE OR REPLACE FUNCTION fn_validate_product_unit() RETURNS TRIGGER AS $$
            DECLARE
                v_track_serial BOOLEAN;
                v_is_service   BOOLEAN;
                v_base_unit_id BIGINT;
            BEGIN
                SELECT track_serial, is_service, base_unit_id INTO v_track_serial, v_is_service, v_base_unit_id
                FROM product WHERE id = NEW.product_id;

                IF v_track_serial AND NEW.unit_id <> v_base_unit_id THEN
                    RAISE EXCEPTION 'Serial-tracked products only support their base unit (product_id=%)', NEW.product_id;
                END IF;

                IF v_is_service AND NEW.unit_id <> v_base_unit_id THEN
                    RAISE EXCEPTION 'Services only support their base unit (product_id=%)', NEW.product_id;
                END IF;

                IF NEW.unit_id = v_base_unit_id THEN
                    IF NEW.factor_to_base <> 1 THEN
                        RAISE EXCEPTION 'The base unit''s own conversion factor must be 1 (product_id=%, given %)', NEW.product_id, NEW.factor_to_base;
                    END IF;
                ELSE
                    IF NEW.factor_to_base <= 1 THEN
                        RAISE EXCEPTION 'Alternate unit must convert to more than 1 base unit — the base unit is defined as the smallest unit (product_id=%, unit_id=%, given factor=%)',
                            NEW.product_id, NEW.unit_id, NEW.factor_to_base;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fn_post_movement(
                p_transaction_id BIGINT,
                p_line_id     BIGINT,
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
                IF EXISTS (SELECT 1 FROM product WHERE id = p_product_id AND is_service) THEN
                    RAISE EXCEPTION 'Service item has no stock (product_id=%)', p_product_id;
                END IF;

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
        // The functions as they were, without the service checks, then the column.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_validate_product_unit() RETURNS TRIGGER AS $$
            DECLARE
                v_track_serial BOOLEAN;
                v_base_unit_id BIGINT;
            BEGIN
                SELECT track_serial, base_unit_id INTO v_track_serial, v_base_unit_id
                FROM product WHERE id = NEW.product_id;

                IF v_track_serial AND NEW.unit_id <> v_base_unit_id THEN
                    RAISE EXCEPTION 'Serial-tracked products only support their base unit (product_id=%)', NEW.product_id;
                END IF;

                IF NEW.unit_id = v_base_unit_id THEN
                    IF NEW.factor_to_base <> 1 THEN
                        RAISE EXCEPTION 'The base unit''s own conversion factor must be 1 (product_id=%, given %)', NEW.product_id, NEW.factor_to_base;
                    END IF;
                ELSE
                    IF NEW.factor_to_base <= 1 THEN
                        RAISE EXCEPTION 'Alternate unit must convert to more than 1 base unit — the base unit is defined as the smallest unit (product_id=%, unit_id=%, given factor=%)',
                            NEW.product_id, NEW.unit_id, NEW.factor_to_base;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fn_post_movement(
                p_transaction_id BIGINT,
                p_line_id     BIGINT,
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

            ALTER TABLE product DROP CONSTRAINT IF EXISTS ck_product_service_untracked, DROP COLUMN IF EXISTS is_service;
            SQL);
    }
};
