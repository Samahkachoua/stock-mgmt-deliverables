-- ============================================================================
-- One-off conversion: UUID ids -> BIGINT identity ids
-- ============================================================================
-- For a database created before database/sql/schema.sql switched to BIGINT
-- ids (2026-10). A database created from the current schema.sql does not need
-- it (the script refuses to run there).
--
-- Run ONCE, after a backup, with the app stopped and the queue empty:
--     pg_dump ... > before_bigint.dump
--     psql -1 -v ON_ERROR_STOP=1 -f database/sql/convert_uuid_to_bigint.sql
-- (-1 = one transaction: it either converts everything or nothing.)
-- Try it on a copy before running it against the hosted project.
--
-- What it does:
--   * gives every row of every table with a UUID id a new BIGINT id, numbered
--     per table in creation order (created_at, then the old id);
--   * rewrites every column that holds such an id - foreign keys, and the
--     columns that point at different tables (audit_log.entity_id,
--     translation.entity_id, approval_threshold.scope_id) - through one
--     old -> new map (UUIDs are unique across tables);
--   * rewrites ids found inside JSON (audit_log before/after data,
--     import_job.warehouse_ids / result, import_row.payload) as id strings,
--     the way the app now writes them;
--   * recreates every primary key, unique key, check, foreign key and index
--     that used those columns, the generated batch_key columns
--     (COALESCE(batch_id, 0)) and the four functions that take or hold ids;
--   * empties sessions and cache: everyone signs in again.
--
-- Known differences with a database built from schema.sql: converted columns
-- move to the end of their table (column order only), and an entity_id that
-- pointed at a row deleted long ago becomes 0.
-- ============================================================================

-- 0. Only for UUID databases, and not while jobs are queued (their payloads hold old ids).
DO $$
BEGIN
    IF (SELECT data_type FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'app_user' AND column_name = 'id') IS DISTINCT FROM 'uuid' THEN
        RAISE EXCEPTION 'app_user.id is not a UUID: this database already uses BIGINT ids.';
    END IF;

    IF EXISTS (SELECT 1 FROM jobs) THEN
        RAISE EXCEPTION 'The jobs table is not empty: stop the app, let the queue finish, then run this again.';
    END IF;
END $$;

-- 1. The functions taking UUIDs; the BIGINT versions are created at the end.
DROP FUNCTION IF EXISTS fn_post_movement(uuid, uuid, uuid, uuid, uuid, uuid, movement_direction, numeric);
DROP FUNCTION IF EXISTS fn_apply_balance(uuid, uuid, uuid, uuid, movement_direction, numeric);
DROP FUNCTION IF EXISTS fn_resolve_threshold(uuid, uuid, character varying);

-- 2. Sessions and cached permissions hold old ids.
TRUNCATE sessions, cache, cache_locks;

-- Triggers (e.g. trg_validate_product_unit) would run on every rewritten row
-- and compare new ids with old ones: off until the ids are converted.
DO $$
DECLARE
    t record;
BEGIN
    FOR t IN SELECT DISTINCT event_object_table AS tbl FROM information_schema.triggers WHERE trigger_schema = 'public' LOOP
        EXECUTE format('ALTER TABLE %I DISABLE TRIGGER USER', t.tbl);
    END LOOP;
END $$;

-- 3. Old id -> new id, for every table whose id is a UUID.
CREATE TEMP TABLE cv_map (old_id uuid PRIMARY KEY, new_id bigint NOT NULL, tbl text NOT NULL) ON COMMIT DROP;

DO $$
DECLARE
    t record;
BEGIN
    FOR t IN
        SELECT c.table_name,
               EXISTS (SELECT 1 FROM information_schema.columns x
                        WHERE x.table_schema = 'public' AND x.table_name = c.table_name AND x.column_name = 'created_at') AS dated
          FROM information_schema.columns c
          JOIN information_schema.tables tb ON tb.table_schema = c.table_schema AND tb.table_name = c.table_name AND tb.table_type = 'BASE TABLE'
         WHERE c.table_schema = 'public' AND c.column_name = 'id' AND c.data_type = 'uuid'
    LOOP
        EXECUTE format(
            'INSERT INTO cv_map (old_id, new_id, tbl) SELECT id, row_number() OVER (ORDER BY %s id), %L FROM %I',
            CASE WHEN t.dated THEN 'created_at NULLS LAST,' ELSE '' END, t.table_name, t.table_name);
    END LOOP;
END $$;

-- 4. Every UUID column to convert (the generated batch_key columns are rebuilt instead).
CREATE TEMP TABLE cv_cols ON COMMIT DROP AS
SELECT c.table_name::text AS tbl, c.column_name::text AS col, c.is_nullable = 'NO' AS not_null
  FROM information_schema.columns c
  JOIN information_schema.tables tb ON tb.table_schema = c.table_schema AND tb.table_name = c.table_name AND tb.table_type = 'BASE TABLE'
 WHERE c.table_schema = 'public' AND c.data_type = 'uuid' AND c.is_generated = 'NEVER';

-- 5. Constraints and indexes over those columns (or batch_key): saved, then dropped.
CREATE TEMP TABLE cv_constraints ON COMMIT DROP AS
SELECT DISTINCT con.conrelid::regclass::text AS tbl, con.conname::text AS name, con.contype AS kind,
       pg_get_constraintdef(con.oid) AS def
  FROM pg_constraint con
  JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = ANY (con.conkey)
 WHERE con.connamespace = 'public'::regnamespace
   AND format_type(a.atttypid, a.atttypmod) = 'uuid';

CREATE TEMP TABLE cv_indexes ON COMMIT DROP AS
SELECT DISTINCT i.indexrelid::regclass::text AS name, pg_get_indexdef(i.indexrelid) AS def
  FROM pg_index i
  JOIN pg_class t ON t.oid = i.indrelid AND t.relnamespace = 'public'::regnamespace
  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
 WHERE format_type(a.atttypid, a.atttypmod) = 'uuid'
   AND NOT EXISTS (SELECT 1 FROM pg_constraint con WHERE con.conindid = i.indexrelid AND con.contype IN ('p', 'u', 'x'));

DO $$
DECLARE
    r record;
BEGIN
    -- Foreign keys first: they depend on the primary / unique keys they point at.
    FOR r IN SELECT * FROM cv_constraints ORDER BY (kind = 'f') DESC LOOP
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %I', r.tbl, r.name);
    END LOOP;

    FOR r IN SELECT * FROM cv_indexes LOOP
        EXECUTE format('DROP INDEX IF EXISTS %s', r.name);
    END LOOP;

    ALTER TABLE stock_balance DROP COLUMN batch_key;
    ALTER TABLE in_transit_balance DROP COLUMN batch_key;
END $$;

-- 6. New BIGINT columns, filled through the map, then swapped in.
DO $$
DECLARE
    c record;
BEGIN
    FOR c IN SELECT * FROM cv_cols LOOP
        EXECUTE format('ALTER TABLE %I ADD COLUMN %I bigint', c.tbl, c.col || '__new');
        EXECUTE format('UPDATE %I t SET %I = m.new_id FROM cv_map m WHERE m.old_id = t.%I', c.tbl, c.col || '__new', c.col);

        IF c.not_null THEN
            -- Only entity_id-style columns can point at a row deleted long ago.
            EXECUTE format('UPDATE %I SET %I = 0 WHERE %I IS NULL', c.tbl, c.col || '__new', c.col || '__new');
        END IF;

        EXECUTE format('ALTER TABLE %I DROP COLUMN %I', c.tbl, c.col);
        EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', c.tbl, c.col || '__new', c.col);

        IF c.not_null THEN
            EXECUTE format('ALTER TABLE %I ALTER COLUMN %I SET NOT NULL', c.tbl, c.col);
        END IF;
    END LOOP;

    -- Ids become identity columns that continue after the highest id.
    FOR c IN SELECT tbl FROM cv_cols WHERE col = 'id' LOOP
        EXECUTE format('ALTER TABLE %I ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY', c.tbl);
        EXECUTE format('SELECT setval(pg_get_serial_sequence(%L, %L), (SELECT COALESCE(max(id), 0) + 1 FROM %I), false)', c.tbl, 'id', c.tbl);
    END LOOP;
END $$;

-- 7. Generated batch keys, as in schema.sql.
ALTER TABLE stock_balance ADD COLUMN batch_key BIGINT GENERATED ALWAYS AS (COALESCE(batch_id, 0)) STORED;
ALTER TABLE in_transit_balance ADD COLUMN batch_key BIGINT GENERATED ALWAYS AS (COALESCE(batch_id, 0)) STORED;

-- 8. Constraints and indexes back, same names and definitions (now over BIGINT columns).
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN SELECT * FROM cv_constraints ORDER BY (kind = 'f'), (kind <> 'p') LOOP
        EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s', r.tbl, r.name, r.def);
    END LOOP;

    FOR r IN SELECT * FROM cv_indexes LOOP
        EXECUTE r.def;
    END LOOP;
END $$;

-- 9. Ids inside JSON: every known old id, wherever it appears, becomes the new id as a string.
CREATE FUNCTION pg_temp.cv_json(doc jsonb) RETURNS jsonb AS $$
DECLARE
    txt text := doc::text;
    m record;
BEGIN
    IF doc IS NULL THEN
        RETURN NULL;
    END IF;

    FOR m IN
        SELECT DISTINCT found[1] AS old_text, map.new_id
          FROM regexp_matches(txt, '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})', 'g') AS found
          JOIN cv_map map ON map.old_id = found[1]::uuid
    LOOP
        txt := replace(txt, '"' || m.old_text || '"', '"' || m.new_id || '"');
    END LOOP;

    RETURN txt::jsonb;
END $$ LANGUAGE plpgsql;

UPDATE audit_log SET before_data = pg_temp.cv_json(before_data), after_data = pg_temp.cv_json(after_data)
 WHERE before_data::text ~* '[0-9a-f]{8}-[0-9a-f]{4}-' OR after_data::text ~* '[0-9a-f]{8}-[0-9a-f]{4}-';
UPDATE import_job SET warehouse_ids = pg_temp.cv_json(warehouse_ids), result = pg_temp.cv_json(result);
UPDATE import_row SET payload = pg_temp.cv_json(payload) WHERE payload::text ~* '[0-9a-f]{8}-[0-9a-f]{4}-';

-- 10. The functions that take or hold ids, copied from database/sql/schema.sql.
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

CREATE OR REPLACE FUNCTION fn_apply_balance(
    p_warehouse_id BIGINT,
    p_customer_id  BIGINT,
    p_product_id   BIGINT,
    p_batch_id     BIGINT,
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
    -- ON CONFLICT targets the generated batch_key column via the primary key.
    INSERT INTO stock_balance (warehouse_id, customer_id, product_id, batch_id, qty_base)
    VALUES (p_warehouse_id, p_customer_id, p_product_id, p_batch_id, 0)
    ON CONFLICT (warehouse_id, customer_id, product_id, batch_key) DO NOTHING;

    SELECT qty_base INTO v_current
    FROM stock_balance
    WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
      AND product_id = p_product_id
      AND batch_key = COALESCE(p_batch_id, 0)
    FOR UPDATE;

    IF v_current + v_delta < 0 THEN
        RAISE EXCEPTION 'Negative stock blocked: warehouse=%, customer=%, product=%, batch=%, available=%, requested_out=%',
            p_warehouse_id, p_customer_id, p_product_id, p_batch_id, v_current, p_qty_base;
    END IF;

    UPDATE stock_balance
       SET qty_base = v_current + v_delta, updated_at = now()
     WHERE warehouse_id = p_warehouse_id AND customer_id = p_customer_id
       AND product_id = p_product_id
       AND batch_key = COALESCE(p_batch_id, 0);
END;
$$ LANGUAGE plpgsql;

-- Posts a stock_transaction: for every line, writes an immutable stock_movement
-- and updates stock_balance. Called by the application after status checks
-- and approval (if required) have passed. NOTE: batch_id in stock_balance/
-- stock_movement uses a fixed sentinel BIGINT for non-batch-tracked products
-- (application layer resolves this; see SRS section on batching).
CREATE OR REPLACE FUNCTION fn_post_movement(
    p_transaction_id BIGINT,
    p_line_id     BIGINT,
    p_warehouse_id BIGINT,
    p_customer_id  BIGINT,
    p_product_id   BIGINT,
    p_batch_id     BIGINT,
    p_direction    movement_direction,
    p_qty_base     NUMERIC
) RETURNS BIGINT AS $$
DECLARE
    v_movement_id BIGINT;
BEGIN
    PERFORM fn_apply_balance(p_warehouse_id, p_customer_id, p_product_id, p_batch_id, p_direction, p_qty_base);

    INSERT INTO stock_movement (transaction_id, line_id, warehouse_id, customer_id, product_id, batch_id, direction, qty_base)
    VALUES (p_transaction_id, p_line_id, p_warehouse_id, p_customer_id, p_product_id, p_batch_id, p_direction, p_qty_base)
    RETURNING id INTO v_movement_id;

    RETURN v_movement_id;
END;
$$ LANGUAGE plpgsql;

-- Resolves the applicable approval threshold for a product/customer/transaction_type
-- using the PRODUCT -> CUSTOMER -> GLOBAL priority described in the SRS.
CREATE OR REPLACE FUNCTION fn_resolve_threshold(
    p_product_id BIGINT,
    p_customer_id BIGINT,
    p_transaction_type VARCHAR(30)
) RETURNS NUMERIC AS $$
DECLARE
    v_threshold NUMERIC(18,4);
BEGIN
    -- 1. Product-level override (stored directly on product for the common case)
    SELECT approval_threshold_qty INTO v_threshold FROM product WHERE id = p_product_id;
    IF v_threshold IS NOT NULL THEN
        RETURN v_threshold;
    END IF;

    -- 1b. Product-scoped row in approval_threshold (finer-grained, per transaction_type)
    SELECT qty_base INTO v_threshold FROM approval_threshold
     WHERE scope = 'PRODUCT' AND scope_id = p_product_id AND transaction_type = p_transaction_type;
    IF v_threshold IS NOT NULL THEN
        RETURN v_threshold;
    END IF;

    -- 2. Customer-level default
    SELECT qty_base INTO v_threshold FROM approval_threshold
     WHERE scope = 'CUSTOMER' AND scope_id = p_customer_id AND transaction_type = p_transaction_type;
    IF v_threshold IS NOT NULL THEN
        RETURN v_threshold;
    END IF;

    -- 3. Global default
    SELECT qty_base INTO v_threshold FROM approval_threshold
     WHERE scope = 'GLOBAL' AND scope_id IS NULL AND transaction_type = p_transaction_type;

    RETURN v_threshold; -- may be NULL -> no threshold configured -> app treats as "always require approval" or "never", per config
END;
$$ LANGUAGE plpgsql;


-- Triggers back on.
DO $$
DECLARE
    t record;
BEGIN
    FOR t IN SELECT DISTINCT event_object_table AS tbl FROM information_schema.triggers WHERE trigger_schema = 'public' LOOP
        EXECUTE format('ALTER TABLE %I ENABLE TRIGGER USER', t.tbl);
    END LOOP;
END $$;

-- 11. Checks: no UUID column left, RLS still on, the ledger still adds up.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND data_type = 'uuid') THEN
        RAISE EXCEPTION 'Conversion incomplete: UUID columns remain.';
    END IF;

    IF EXISTS (SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND NOT rowsecurity) THEN
        RAISE EXCEPTION 'A public table lost row level security.';
    END IF;

    IF EXISTS (
        SELECT 1
          FROM (SELECT warehouse_id, customer_id, product_id, COALESCE(batch_id, 0) AS batch_key,
                       SUM(CASE WHEN direction = 'IN' THEN qty_base ELSE -qty_base END) AS qty
                  FROM stock_movement
                 GROUP BY 1, 2, 3, 4) l
          FULL JOIN stock_balance b USING (warehouse_id, customer_id, product_id, batch_key)
         WHERE COALESCE(l.qty, 0) <> COALESCE(b.qty_base, 0)
    ) THEN
        RAISE EXCEPTION 'stock_balance no longer matches stock_movement.';
    END IF;

    RAISE NOTICE 'Converted % rows in % tables to BIGINT ids.', (SELECT count(*) FROM cv_map), (SELECT count(*) FROM cv_cols WHERE col = 'id');
END $$;
