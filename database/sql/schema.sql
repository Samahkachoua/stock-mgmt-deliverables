-- ============================================================================
-- Multi-Warehouse Stock Management System — PostgreSQL Schema
-- Logistics / 3PL domain: customer-owned stock, ledger-based movements,
-- unit-of-measure conversions, expiry dates, serials, transfers, approvals.
-- ============================================================================
-- Target: Supabase (PostgreSQL 15+), accessed by a Laravel application.
--
-- This file describes the WHOLE current database: every table (Laravel's own
-- cache / jobs / sessions tables too), column, index, function and the seed
-- data, as all migrations leave it. Only Laravel's `migrations` table is not
-- here: Laravel creates it itself before running this file. When a migration
-- changes the database, fold the change into this file as well and make that
-- migration do nothing when the change is already there (see CLAUDE.md).
--
-- How to run:
--   * From Laravel: the first migration loads this file with DB::unprepared().
--     Laravel already wraps each migration in a transaction, so this file
--     deliberately contains NO BEGIN/COMMIT (a raw COMMIT inside a Laravel
--     migration breaks its transaction handling).
--   * Standalone:   psql -1 -v ON_ERROR_STOP=1 -f schema.sql
--     (-1 runs the whole file as a single transaction).
--
-- No extensions are required: gen_random_uuid() is built into PostgreSQL 13+,
-- and emails use VARCHAR + a unique index on lower(email) instead of CITEXT
-- (Supabase installs extensions into the "extensions" schema, which Laravel's
-- default search_path of "public" does not include).
-- ============================================================================

-- ----------------------------------------------------------------------------
-- ENUM TYPES
-- ----------------------------------------------------------------------------
-- transaction_type and transaction_status used to be ENUMs here. They are now
-- proper reference tables (see "TRANSACTION TYPE & STATUS (lookup tables)"
-- below, defined before stock_transaction) so that:
--   - new types/statuses can be added with an INSERT instead of ALTER TYPE
--     (ALTER TYPE ... ADD VALUE cannot run inside a transaction in older PG
--      versions and cannot be dropped at all),
--   - each type/status can carry its own metadata (workflow flags, display
--     order, translations) instead of being a bare code, and
--   - the application can list valid types/statuses via a normal SELECT.

CREATE TYPE movement_direction AS ENUM ('IN', 'OUT');

CREATE TYPE approval_scope AS ENUM ('GLOBAL', 'CUSTOMER', 'PRODUCT');

CREATE TYPE approval_decision AS ENUM ('APPROVED', 'REJECTED');

CREATE TYPE serial_status AS ENUM ('IN_STOCK', 'ISSUED', 'DAMAGED', 'IN_TRANSIT');

CREATE TYPE adjustment_reason AS ENUM (
    'STOCK_COUNT_VARIANCE', 'LOSS_THEFT', 'DATA_ENTRY_CORRECTION', 'OTHER'
);

CREATE TYPE damage_reason AS ENUM (
    'HANDLING', 'TRANSPORT', 'EXPIRED', 'WAREHOUSE_INCIDENT', 'OTHER'
);

-- ----------------------------------------------------------------------------
-- USERS, ROLES, PERMISSIONS
-- ----------------------------------------------------------------------------

CREATE TABLE app_user (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,   -- unique case-insensitively, see uq_app_user_email
    full_name       VARCHAR(150) NOT NULL,
    password_hash   TEXT NOT NULL,           -- Laravel model: protected $authPasswordName = 'password_hash';
    remember_token  VARCHAR(100),            -- required by Laravel's "remember me"
    -- Set for the customer's own staff (a "customer user"): they only ever see
    -- that customer's data (CustomerScope). NULL for the warehouse company's
    -- staff. FK added after the customer table.
    customer_id     BIGINT NULL,
    -- The warehouse pages start on (forms, lists, reports); one of the user's
    -- user_warehouse rows. NULL = their first warehouse. FK added after the warehouse table.
    default_warehouse_id BIGINT NULL,
    preferred_lang  VARCHAR(5) NOT NULL DEFAULT 'en', -- en | ar | fr
    preferred_theme VARCHAR(10) NOT NULL DEFAULT 'system'
                    CHECK (preferred_theme IN ('light', 'dark', 'system')),
    -- Email when an order in their warehouses is added, changed or cancelled (only with the orders'
    -- confirm permission); Settings › Users "Email about orders".
    notify_new_orders BOOLEAN NOT NULL DEFAULT TRUE,
    -- The same for draft receipts (only with their confirm permission); "Email about draft receipts".
    notify_draft_receipts BOOLEAN NOT NULL DEFAULT TRUE,
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_app_user_email ON app_user (lower(email));

CREATE TABLE role (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(50) NOT NULL,   -- ADMIN, MANAGER, OPERATOR, DATA_ENTRY, AUDITOR, CUSTOMER
    name        VARCHAR(100) NOT NULL,
    is_system   BOOLEAN NOT NULL DEFAULT FALSE, -- system roles cannot be deleted
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, name)
);

CREATE TABLE permission (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(100) NOT NULL UNIQUE, -- e.g. stock.in.create, damage.approve, product.import
    description VARCHAR(255) NOT NULL,
    module      VARCHAR(50) NOT NULL          -- e.g. STOCK, PRODUCT, REPORT, ADMIN
);

CREATE TABLE role_permission (
    role_id       BIGINT NOT NULL REFERENCES role(id) ON DELETE CASCADE,
    permission_id BIGINT NOT NULL REFERENCES permission(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE user_role (
    user_id BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    role_id BIGINT NOT NULL REFERENCES role(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

-- ----------------------------------------------------------------------------
-- COMPANY SETTINGS (Settings › General)
-- ----------------------------------------------------------------------------
-- One row per setting. 'timezone': the IANA time zone the whole company works
-- in (what "today" is, how stored UTC times are shown); App\Support\CompanyTime.
CREATE TABLE app_setting (
    key         VARCHAR(50) PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_by  BIGINT REFERENCES app_user(id),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO app_setting (key, value) VALUES ('timezone', 'Asia/Beirut');

-- ----------------------------------------------------------------------------
-- WAREHOUSES & CUSTOMERS
-- ----------------------------------------------------------------------------

CREATE TABLE warehouse (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(150) NOT NULL,
    city        VARCHAR(100) NOT NULL,   -- one warehouse = one location/city
    address     TEXT,
    -- No time zone here: every warehouse works in the company's (app_setting 'timezone').
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, name)
);

CREATE TABLE user_warehouse (           -- restrict which warehouses a user may post in
    user_id      BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    warehouse_id BIGINT NOT NULL REFERENCES warehouse(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, warehouse_id)
);

ALTER TABLE app_user
    ADD CONSTRAINT fk_user_default_warehouse FOREIGN KEY (default_warehouse_id) REFERENCES warehouse(id);

CREATE TABLE customer (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(150) NOT NULL,
    contact_email VARCHAR(255),
    contact_phone VARCHAR(50),
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, name)
);

ALTER TABLE app_user
    ADD CONSTRAINT fk_user_customer FOREIGN KEY (customer_id) REFERENCES customer(id);

-- A customer's branches (shops, sites). Shown on draft receipts / orders as
-- where the goods come from or go to; they never affect stock.
CREATE TABLE customer_branch (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customer(id),
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(150) NOT NULL,
    address     TEXT,
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (customer_id, code),
    UNIQUE (id, customer_id)          -- target of the (branch, customer) foreign keys below
);

-- Branches a customer user works for (optional). A user with none sees every
-- branch of their customer.
CREATE TABLE user_customer_branch (
    user_id   BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    branch_id BIGINT NOT NULL REFERENCES customer_branch(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, branch_id)
);

-- A user may only be linked to branches of their own customer.
CREATE OR REPLACE FUNCTION fn_check_user_branch() RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM app_user u JOIN customer_branch b ON b.customer_id = u.customer_id
         WHERE u.id = NEW.user_id AND b.id = NEW.branch_id
    ) THEN
        RAISE EXCEPTION 'Branch % does not belong to the customer of user %', NEW.branch_id, NEW.user_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_check_user_branch
    BEFORE INSERT OR UPDATE ON user_customer_branch
    FOR EACH ROW EXECUTE FUNCTION fn_check_user_branch();

-- ----------------------------------------------------------------------------
-- UNITS OF MEASURE
-- ----------------------------------------------------------------------------

CREATE TABLE unit (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        VARCHAR(20) NOT NULL,     -- PC, BOX, PLT, KG
    name        VARCHAR(100) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (code, name)
);

-- ----------------------------------------------------------------------------
-- PRODUCTS (owned by a customer)
-- ----------------------------------------------------------------------------

-- How cold an item must be kept (shown on the item, in the item picker and on
-- printouts). Optional per item; the range is in degrees Celsius.
CREATE TABLE storage_status (
    code           VARCHAR(20) PRIMARY KEY,   -- AMBIENT, COOL, CHILLED, FROZEN, DEEP_FROZEN
    temp_min_c     SMALLINT NOT NULL,
    temp_max_c     SMALLINT NOT NULL,
    display_order  SMALLINT NOT NULL,
    CHECK (temp_max_c > temp_min_c)
);

CREATE TABLE product (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id             BIGINT NOT NULL REFERENCES customer(id),
    sku                     VARCHAR(50) NOT NULL,
    name_en                 VARCHAR(200) NOT NULL,
    name_ar                 VARCHAR(200),
    name_fr                 VARCHAR(200),
    base_unit_id            BIGINT NOT NULL REFERENCES unit(id),
    track_expiry            BOOLEAN NOT NULL DEFAULT FALSE,
    track_serial            BOOLEAN NOT NULL DEFAULT FALSE,
    -- A service (packing, delivery fee...) is a line on receipts and delivery
    -- notes but never stock: no movement, balance, expiry or serial.
    is_service              BOOLEAN NOT NULL DEFAULT FALSE,
    storage_status          VARCHAR(20) REFERENCES storage_status(code),  -- NULL = not set
    approval_threshold_qty  NUMERIC(18,4),        -- product-level override; NULL = fall back to customer/global
    active                  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (customer_id, sku),
    CONSTRAINT ck_product_service_untracked
        CHECK (NOT is_service OR (NOT track_expiry AND NOT track_serial))
    -- Rule "a serial-tracked product (or a service) may only use its base unit"
    -- is enforced by the trg_validate_product_unit trigger on product_unit below.
);

-- Alternate units + conversion factor to base unit. The base unit is always the
-- SMALLEST unit a product is stocked in (e.g. Piece, not Box): factor_to_base
-- for the base unit's own row is always 1, and every alternate (larger) unit
-- must convert to MORE than 1 base unit (e.g. 1 Box = 12 Piece). This is
-- enforced by trg_validate_product_unit below, not just by convention.
CREATE TABLE product_unit (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES product(id) ON DELETE CASCADE,
    unit_id         BIGINT NOT NULL REFERENCES unit(id),
    factor_to_base  NUMERIC(18,6) NOT NULL CHECK (factor_to_base >= 1), -- 1 of this unit = factor_to_base base units
    is_default      BOOLEAN NOT NULL DEFAULT FALSE,  -- exactly one row (the base unit itself) should be default
    UNIQUE (product_id, unit_id)
);

CREATE TABLE product_barcode (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES product(id) ON DELETE CASCADE,
    unit_id     BIGINT REFERENCES unit(id),            -- NULL = barcode applies at base-unit level
    barcode     VARCHAR(100) NOT NULL,
    UNIQUE (barcode)
);

-- Enforces two rules on every product_unit row:
--   1. Serial-tracked products and services may only have their base unit (no alt units).
--   2. The base unit is always the smallest unit: the base unit's own row (if
--      present) must have factor_to_base = 1, and every alternate (non-base)
--      unit must have factor_to_base > 1 (it must represent MORE than one
--      base unit — e.g. 1 Box = 12 Piece, never 1 Box = 1 Piece or 0.5 Piece).
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

CREATE TRIGGER trg_validate_product_unit
    BEFORE INSERT OR UPDATE ON product_unit
    FOR EACH ROW EXECUTE FUNCTION fn_validate_product_unit();

-- ----------------------------------------------------------------------------
-- EXPIRY (for expiry-tracked products)
-- ----------------------------------------------------------------------------
-- Stock of an expiry-tracked product is kept per expiry date AND batch number.
-- Lines, movements, balances and in-transit rows carry expiry_date and batch_no
-- themselves (both NULL for products that don't track expiry; batch_no NULL =
-- "no batch", stock from before batches existed).
--
-- product_batch: one row per item and batch number, with its one expiry date
-- (a batch never has two dates). Lines and movements reference it through
-- (product_id, batch_no, expiry_date); a NULL batch_no skips the reference.
CREATE TABLE product_batch (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES product(id),
    batch_no    VARCHAR(40) NOT NULL CHECK (batch_no <> '' AND batch_no = upper(btrim(batch_no))),
    expiry_date DATE NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_product_batch UNIQUE (product_id, batch_no),
    CONSTRAINT uq_product_batch_expiry UNIQUE (product_id, batch_no, expiry_date)
);

-- Only items that track expiry have batches.
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

-- ----------------------------------------------------------------------------
-- SERIAL NUMBERS (for serial-tracked products)
-- ----------------------------------------------------------------------------

CREATE TABLE serial_number (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id   BIGINT NOT NULL REFERENCES product(id),
    serial_no    VARCHAR(150) NOT NULL,
    warehouse_id BIGINT REFERENCES warehouse(id),   -- current location; NULL if not yet received
    status       serial_status NOT NULL DEFAULT 'IN_STOCK',
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, serial_no)
);

-- ----------------------------------------------------------------------------
-- TRANSACTION TYPE & STATUS (lookup tables, replacing what used to be ENUMs)
-- ----------------------------------------------------------------------------

CREATE TABLE transaction_type (
    code                            VARCHAR(30) PRIMARY KEY,  -- OPENING_BALANCE, IN, OUT, TRANSFER, DAMAGE, ADJUSTMENT
    name                            VARCHAR(100) NOT NULL,
    requires_destination_warehouse  BOOLEAN NOT NULL DEFAULT FALSE,  -- true only for TRANSFER
    requires_dispatch_receive       BOOLEAN NOT NULL DEFAULT FALSE,  -- true only for TRANSFER
    supports_approval_threshold     BOOLEAN NOT NULL DEFAULT FALSE,  -- true for DAMAGE, ADJUSTMENT
    display_order                   SMALLINT NOT NULL DEFAULT 0,
    active                          BOOLEAN NOT NULL DEFAULT TRUE,
    -- What a type does to stock, as data (CLAUDE.md rule 4: no switch over type codes in PHP).
    --   posting_direction: IN = adds stock, OUT = takes it; NULL = decided per line
    --                      (Adjustment) or in two steps (Transfer).
    --   uses_expired:      may take expired stock (Damage writes it off);
    --                      otherwise expired stock is skipped.
    posting_direction               movement_direction,
    uses_expired                    BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE transaction_status (
    code           VARCHAR(30) PRIMARY KEY,  -- DRAFT, PENDING_APPROVAL, APPROVED, REJECTED, DISPATCHED, RECEIVED, POSTED, CANCELLED
    name           VARCHAR(100) NOT NULL,
    is_terminal    BOOLEAN NOT NULL DEFAULT FALSE,  -- POSTED, REJECTED, CANCELLED
    affects_stock  BOOLEAN NOT NULL DEFAULT FALSE,  -- true only for POSTED (and DISPATCHED, via in-transit)
    display_order  SMALLINT NOT NULL DEFAULT 0,
    UNIQUE (name)
);

-- ----------------------------------------------------------------------------
-- APPROVAL THRESHOLDS (lookup order: PRODUCT -> CUSTOMER -> GLOBAL)
-- ----------------------------------------------------------------------------

CREATE TABLE approval_threshold (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope            approval_scope NOT NULL,
    scope_id         BIGINT NULL,              -- customer_id or product_id; NULL when scope = GLOBAL
    transaction_type VARCHAR(30) NOT NULL REFERENCES transaction_type(code), -- DAMAGE or ADJUSTMENT typically
    qty_base         NUMERIC(18,4) NOT NULL CHECK (qty_base >= 0),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (scope, scope_id, transaction_type)
);

-- ----------------------------------------------------------------------------
-- STOCK TRANSACTIONS (header) + MOVEMENTS (immutable ledger lines) + BALANCES (cache)
-- ----------------------------------------------------------------------------

CREATE TABLE stock_transaction (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    doc_no           VARCHAR(30) NOT NULL UNIQUE,      -- human-readable reference, e.g. TRF-2026-000123
    transaction_type VARCHAR(30) NOT NULL REFERENCES transaction_type(code),
    status           VARCHAR(30) NOT NULL DEFAULT 'DRAFT' REFERENCES transaction_status(code),
    warehouse_id     BIGINT NOT NULL REFERENCES warehouse(id),      -- source warehouse
    to_warehouse_id  BIGINT REFERENCES warehouse(id),               -- destination, TRANSFER only
    customer_id      BIGINT REFERENCES customer(id),                -- NULL only allowed for cross-customer docs (not expected)
    reason_code      VARCHAR(30),        -- damage_reason / adjustment_reason as text, validated in app layer
    reference_note   TEXT,
    -- The date the user gives the document (defaults to today; an Opening
    -- Balance may be dated back). created_at / posted_at are when it was recorded.
    document_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    -- Submission token of the form that created it (a UUID made when the form
    -- opens): the same form submitted twice creates one document. NULL for
    -- documents not created from a form (imports).
    client_request_id UUID,
    -- Receipts / delivery notes created by confirming a draft: the draft and its
    -- branch (information only). FK to stock_draft added after that table.
    customer_branch_id BIGINT REFERENCES customer_branch(id),
    draft_id         BIGINT,
    created_by       BIGINT NOT NULL REFERENCES app_user(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    dispatched_at    TIMESTAMPTZ,
    dispatched_by    BIGINT REFERENCES app_user(id),
    received_at      TIMESTAMPTZ,
    received_by      BIGINT REFERENCES app_user(id),
    posted_at        TIMESTAMPTZ,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_transfer_has_destination
        CHECK (transaction_type <> 'TRANSFER' OR to_warehouse_id IS NOT NULL),
    CONSTRAINT chk_transfer_diff_warehouse
        CHECK (transaction_type <> 'TRANSFER' OR to_warehouse_id <> warehouse_id),
    CONSTRAINT uq_stock_transaction_client_request UNIQUE (client_request_id)
);

CREATE INDEX idx_stock_transaction_wh_status ON stock_transaction (warehouse_id, status);
CREATE INDEX idx_stock_transaction_type_status ON stock_transaction (transaction_type, status);
CREATE INDEX idx_stock_transaction_list ON stock_transaction (warehouse_id, transaction_type, document_date DESC);

-- Reference numbers (doc_no): one sequence per transaction type, read with
-- nextval() inside the posting transaction (App\Services\Stock\DocumentNumbers),
-- so two documents never get the same number. Sequences are not transactional:
-- a rolled-back save leaves a gap in the numbering. They belong to no table,
-- so `migrate:fresh` leaves them behind: drop them first to restart at 1.
DROP SEQUENCE IF EXISTS doc_no_opening_balance_seq, doc_no_in_seq, doc_no_out_seq,
    doc_no_transfer_seq, doc_no_adjustment_seq, doc_no_damage_seq,
    doc_no_draft_in_seq, doc_no_draft_out_seq;
CREATE SEQUENCE doc_no_opening_balance_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_in_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_out_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_transfer_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_adjustment_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_damage_seq AS BIGINT START 1;
-- Draft receipts (DRR-) and orders (ORD-; DRI- before the rename), see stock_draft.
CREATE SEQUENCE doc_no_draft_in_seq AS BIGINT START 1;
CREATE SEQUENCE doc_no_draft_out_seq AS BIGINT START 1;

CREATE TABLE stock_transaction_line (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_id     BIGINT NOT NULL REFERENCES stock_transaction(id) ON DELETE CASCADE,
    line_no         INT NOT NULL,
    product_id      BIGINT NOT NULL REFERENCES product(id),
    expiry_date     DATE,               -- expiry-tracked products: received, or chosen when going out
    batch_no        VARCHAR(40),        -- expiry-tracked products: the batch of that expiry date
    unit_id         BIGINT NOT NULL REFERENCES unit(id),
    qty_entered     NUMERIC(18,4) NOT NULL CHECK (qty_entered > 0),
    qty_base        NUMERIC(18,4) NOT NULL CHECK (qty_base > 0),  -- computed: qty_entered * factor_to_base
    qty_received    NUMERIC(18,4),      -- TRANSFER only: actual qty received (may be < qty_base -> short receipt)
    -- TRANSFER only: why the line arrived short (required when qty_received < qty_base).
    -- The codes are labelled in lang/<locale>/transfers.php.
    shortage_reason VARCHAR(30),
    -- ADJUSTMENT only (user enters what they physically counted):
    --   qty_system  = balance in base units at the moment of saving
    --   qty_counted = counted quantity in base units (may be 0)
    --   direction   = IN if counted > system, OUT if counted < system
    --   qty_entered / qty_base hold the absolute difference (lines with no
    --   difference are not saved).
    qty_system      NUMERIC(18,4),
    qty_counted     NUMERIC(18,4) CHECK (qty_counted >= 0),
    direction       movement_direction,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (transaction_id, line_no),
    CONSTRAINT chk_adjustment_line_complete
        CHECK ((qty_counted IS NULL AND qty_system IS NULL AND direction IS NULL)
            OR (qty_counted IS NOT NULL AND qty_system IS NOT NULL AND direction IS NOT NULL
                AND qty_base = abs(qty_counted - qty_system))),
    CONSTRAINT chk_shortage_reason
        CHECK (shortage_reason IS NULL OR shortage_reason IN ('DAMAGED_IN_TRANSIT', 'MISSING', 'COUNTING_ERROR')),
    CONSTRAINT fk_transaction_line_batch FOREIGN KEY (product_id, batch_no, expiry_date)
        REFERENCES product_batch (product_id, batch_no, expiry_date)
);

-- No duplicate lines (CLAUDE.md rule 12): one line per item + unit + expiry date
-- + batch in a document, "no expiry" / "no batch" as one value each. The app
-- refuses them first, with a message.
CREATE UNIQUE INDEX uq_transaction_line_key
    ON stock_transaction_line (transaction_id, product_id, unit_id, COALESCE(expiry_date, DATE '0001-01-01'), COALESCE(batch_no, ''));

CREATE TABLE stock_transaction_line_serial (   -- serials attached to a transaction line
    line_id         BIGINT NOT NULL REFERENCES stock_transaction_line(id) ON DELETE CASCADE,
    serial_id       BIGINT NOT NULL REFERENCES serial_number(id),
    PRIMARY KEY (line_id, serial_id)
);

-- ----------------------------------------------------------------------------
-- DRAFTS (draft receipt / order)
-- ----------------------------------------------------------------------------
-- A draft never touches stock. It is for one warehouse (in the header, like a
-- receipt / delivery note); it can be edited until it is confirmed. Confirming
-- (App\Services\Stock\PostingService::confirmDraft) creates one posted document
-- of transaction_type in that warehouse, pointing back here (stock_transaction.draft_id).
--   status: DRAFT -> POSTED (shown as "Confirmed") or CANCELLED.
CREATE TABLE stock_draft (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    doc_no             VARCHAR(30) NOT NULL UNIQUE,      -- e.g. DRR-2026-000004
    transaction_type   VARCHAR(30) NOT NULL REFERENCES transaction_type(code), -- what confirming creates (IN / OUT)
    status             VARCHAR(30) NOT NULL DEFAULT 'DRAFT' REFERENCES transaction_status(code),
    customer_id        BIGINT NOT NULL REFERENCES customer(id),
    customer_branch_id BIGINT NOT NULL,
    document_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    -- Orders only (draft receipts leave them empty): when to deliver, how urgent.
    delivery_date      DATE,
    priority           VARCHAR(10)
        CONSTRAINT ck_stock_draft_priority CHECK (priority IN ('URGENT', 'NORMAL', 'LOW')),
    reference_note     TEXT,
    client_request_id  UUID UNIQUE,                      -- submission token of the form that created it
    -- Stock comes into / goes out of this warehouse when confirmed.
    warehouse_id      BIGINT NOT NULL REFERENCES warehouse(id),
    created_by         BIGINT NOT NULL REFERENCES app_user(id),
    updated_by         BIGINT REFERENCES app_user(id),
    confirmed_by       BIGINT REFERENCES app_user(id),   -- may be the creator
    confirmed_at       TIMESTAMPTZ,
    cancel_reason      TEXT,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- The branch is one of the draft's customer's.
    CONSTRAINT fk_stock_draft_branch FOREIGN KEY (customer_branch_id, customer_id)
        REFERENCES customer_branch (id, customer_id),
    CONSTRAINT ck_stock_draft_delivery
        CHECK (transaction_type <> 'OUT' OR (delivery_date IS NOT NULL AND priority IS NOT NULL)),
    CONSTRAINT ck_stock_draft_delivery_date
        CHECK (delivery_date IS NULL OR delivery_date >= document_date)
);

CREATE INDEX idx_stock_draft_list ON stock_draft (transaction_type, customer_id, document_date DESC);
-- Which drafts a user may see (their warehouses).
CREATE INDEX idx_stock_draft_warehouse ON stock_draft (warehouse_id, transaction_type);

CREATE TABLE stock_draft_line (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    draft_id     BIGINT NOT NULL REFERENCES stock_draft(id) ON DELETE CASCADE,
    line_no      INT NOT NULL,
    product_id   BIGINT NOT NULL REFERENCES product(id),
    unit_id      BIGINT NOT NULL REFERENCES unit(id),
    qty_entered  NUMERIC(18,4) NOT NULL CHECK (qty_entered > 0),
    qty_base     NUMERIC(18,4) NOT NULL CHECK (qty_base > 0),
    expiry_date  DATE,                                  -- typed (receipt) or picked (order) for expiry items
    batch_no     VARCHAR(40),                           -- with the expiry date: typed (receipt) or picked (order)
    serials      JSONB NOT NULL DEFAULT '[]'::jsonb,    -- serial numbers, for serial-numbered items
    UNIQUE (draft_id, line_no)
);

ALTER TABLE stock_transaction
    ADD CONSTRAINT fk_stock_transaction_draft FOREIGN KEY (draft_id) REFERENCES stock_draft(id);

CREATE INDEX idx_stock_transaction_draft ON stock_transaction (draft_id);

-- Immutable ledger: every posted movement writes one row here (never updated/deleted).
-- seq is the order movements were posted in: created_at is the posting
-- transaction's start, shared by every movement of a document, so the stock
-- card and "balance after" need a strict order of their own.
CREATE SEQUENCE stock_movement_seq_seq AS BIGINT;

CREATE TABLE stock_movement (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_id  BIGINT NOT NULL REFERENCES stock_transaction(id),
    line_id      BIGINT NOT NULL REFERENCES stock_transaction_line(id),
    warehouse_id BIGINT NOT NULL REFERENCES warehouse(id),
    customer_id  BIGINT NOT NULL REFERENCES customer(id),
    product_id   BIGINT NOT NULL REFERENCES product(id),
    expiry_date  DATE,
    batch_no     VARCHAR(40),
    direction    movement_direction NOT NULL,
    qty_base     NUMERIC(18,4) NOT NULL CHECK (qty_base > 0),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    seq          BIGINT NOT NULL DEFAULT nextval('stock_movement_seq_seq'),
    CONSTRAINT fk_movement_batch FOREIGN KEY (product_id, batch_no, expiry_date)
        REFERENCES product_batch (product_id, batch_no, expiry_date)
);

ALTER SEQUENCE stock_movement_seq_seq OWNED BY stock_movement.seq;

CREATE INDEX idx_movement_lookup ON stock_movement (warehouse_id, customer_id, product_id, expiry_date, batch_no);
CREATE INDEX idx_movement_transaction ON stock_movement (transaction_id);
CREATE UNIQUE INDEX uq_stock_movement_seq ON stock_movement (seq);
CREATE INDEX idx_movement_card ON stock_movement (product_id, warehouse_id, seq);
-- Receiving / cancelling reads a line's movements inside the posting transaction.
CREATE INDEX idx_movement_line ON stock_movement (line_id);

-- Cached balance, kept in sync with stock_movement in the same transaction (see fn_post_movement).
-- expiry_date / batch_no are NULL for products that don't track expiry (batch_no
-- also for stock from before batches). Generated columns collapse NULL to a fixed
-- sentinel so the primary key still enforces one row per (warehouse, customer,
-- product, expiry-date-or-none, batch-or-none).
CREATE TABLE stock_balance (
    warehouse_id BIGINT NOT NULL REFERENCES warehouse(id),
    customer_id  BIGINT NOT NULL REFERENCES customer(id),
    product_id   BIGINT NOT NULL REFERENCES product(id),
    expiry_date  DATE,
    expiry_key   DATE GENERATED ALWAYS AS (COALESCE(expiry_date, DATE '0001-01-01')) STORED,
    batch_no     VARCHAR(40),
    batch_key    VARCHAR(40) GENERATED ALWAYS AS (COALESCE(batch_no, '')) STORED,
    qty_base     NUMERIC(18,4) NOT NULL DEFAULT 0 CHECK (qty_base >= 0),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (warehouse_id, customer_id, product_id, expiry_key, batch_key)
);

-- In-transit balance while a TRANSFER is dispatched but not yet received.
CREATE TABLE in_transit_balance (
    transaction_id  BIGINT NOT NULL REFERENCES stock_transaction(id),
    product_id   BIGINT NOT NULL REFERENCES product(id),
    expiry_date  DATE,
    expiry_key   DATE GENERATED ALWAYS AS (COALESCE(expiry_date, DATE '0001-01-01')) STORED,
    batch_no     VARCHAR(40),
    batch_key    VARCHAR(40) GENERATED ALWAYS AS (COALESCE(batch_no, '')) STORED,
    qty_base     NUMERIC(18,4) NOT NULL CHECK (qty_base >= 0),
    PRIMARY KEY (transaction_id, product_id, expiry_key, batch_key)
);

-- ----------------------------------------------------------------------------
-- APPROVALS
-- ----------------------------------------------------------------------------

CREATE TABLE approval (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_id  BIGINT NOT NULL REFERENCES stock_transaction(id),
    requested_by BIGINT NOT NULL REFERENCES app_user(id),
    decided_by   BIGINT REFERENCES app_user(id),
    decision     approval_decision,
    reason       TEXT,
    requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    decided_at   TIMESTAMPTZ,
    CONSTRAINT chk_approver_not_requester CHECK (decided_by IS NULL OR decided_by <> requested_by)
);

-- Pending approvals, and the approver shown on reports.
CREATE INDEX idx_approval_transaction ON approval (transaction_id);

-- ----------------------------------------------------------------------------
-- AUDIT LOG
-- ----------------------------------------------------------------------------

CREATE TABLE audit_log (
    id          BIGSERIAL PRIMARY KEY,
    entity      VARCHAR(50) NOT NULL,
    entity_id   BIGINT NOT NULL,
    action      VARCHAR(30) NOT NULL,     -- CREATE, UPDATE, POST, APPROVE, REJECT, CANCEL
    user_id     BIGINT REFERENCES app_user(id),
    before_data JSONB,
    after_data  JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_entity ON audit_log (entity, entity_id);
-- The audit log report: newest first, by period.
CREATE INDEX idx_audit_created ON audit_log (created_at DESC, id DESC);

-- ----------------------------------------------------------------------------
-- IMPORT (Excel staging)
-- ----------------------------------------------------------------------------

-- The staged import (App\Imports\ImportEngine).
-- import_job.status: UPLOADED (queued / being read) -> VALIDATED -> CONFIRMED,
-- or FAILED (unreadable, wrong template, too many rows).
CREATE TABLE import_job (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_type     VARCHAR(30) NOT NULL,   -- PRODUCT, OPENING_BALANCE
    file_name    VARCHAR(255) NOT NULL,
    status       VARCHAR(20) NOT NULL DEFAULT 'UPLOADED', -- UPLOADED, VALIDATED, CONFIRMED, FAILED
    created_by   BIGINT NOT NULL REFERENCES app_user(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    confirmed_at TIMESTAMPTZ,
    disk           VARCHAR(50),          -- where the uploaded file is kept
    path           VARCHAR(500),
    total_rows     INT NOT NULL DEFAULT 0,   -- reading progress
    processed_rows INT NOT NULL DEFAULT 0,
    error_message  TEXT,                 -- why a file was refused
    headers        JSONB,                -- column headers as uploaded (for the error report)
    warehouse_ids  JSONB,                -- warehouses an opening-balance file touches (who may see the job)
    result         JSONB,                -- what was created
    confirmed_by   BIGINT REFERENCES app_user(id)
);

CREATE INDEX idx_import_job_created ON import_job (job_type, created_at DESC);

-- import_row.status: PENDING -> VALID | WARNING | ERROR -> IMPORTED.
CREATE TABLE import_row (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    job_id     BIGINT NOT NULL REFERENCES import_job(id) ON DELETE CASCADE,
    row_no     INT NOT NULL,
    payload    JSONB NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'PENDING', -- PENDING, VALID, WARNING, ERROR, IMPORTED
    message    TEXT,
    UNIQUE (job_id, row_no)
);

CREATE INDEX idx_import_row_status ON import_row (job_id, status);

-- ----------------------------------------------------------------------------
-- TRANSLATIONS (generic, for any entity field beyond product name_en/ar/fr)
-- ----------------------------------------------------------------------------

CREATE TABLE translation (
    entity      VARCHAR(50) NOT NULL,
    entity_id   BIGINT NOT NULL,
    lang        VARCHAR(5) NOT NULL,
    field       VARCHAR(50) NOT NULL,
    text        TEXT NOT NULL,
    PRIMARY KEY (entity, entity_id, lang, field)
);

-- ----------------------------------------------------------------------------
-- LARAVEL'S OWN TABLES (cache, queue, sessions, password resets)
-- ----------------------------------------------------------------------------
-- As Laravel's default migrations create them on PostgreSQL. There is no
-- `users` table: app_user replaces it. sessions.user_id is app_user.id
-- (no foreign key, as in Laravel's default).

CREATE TABLE cache (
    key        VARCHAR(255) PRIMARY KEY,
    value      TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE INDEX cache_expiration_index ON cache (expiration);

CREATE TABLE cache_locks (
    key        VARCHAR(255) PRIMARY KEY,
    owner      VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE INDEX cache_locks_expiration_index ON cache_locks (expiration);

CREATE TABLE jobs (
    id           BIGSERIAL PRIMARY KEY,
    queue        VARCHAR(255) NOT NULL,
    payload      TEXT NOT NULL,
    attempts     SMALLINT NOT NULL,
    reserved_at  INTEGER,
    available_at INTEGER NOT NULL,
    created_at   INTEGER NOT NULL
);

CREATE INDEX jobs_queue_index ON jobs (queue);

CREATE TABLE job_batches (
    id             VARCHAR(255) PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    total_jobs     INTEGER NOT NULL,
    pending_jobs   INTEGER NOT NULL,
    failed_jobs    INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options        TEXT,
    cancelled_at   INTEGER,
    created_at     INTEGER NOT NULL,
    finished_at    INTEGER
);

CREATE TABLE failed_jobs (
    id         BIGSERIAL PRIMARY KEY,
    uuid       VARCHAR(255) NOT NULL,
    connection TEXT NOT NULL,
    queue      TEXT NOT NULL,
    payload    TEXT NOT NULL,
    exception  TEXT NOT NULL,
    failed_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
);

CREATE TABLE password_reset_tokens (
    email      VARCHAR(255) PRIMARY KEY,
    token      VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE sessions (
    id            VARCHAR(255) PRIMARY KEY,
    user_id       BIGINT,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    payload       TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);

CREATE INDEX sessions_user_id_index ON sessions (user_id);
CREATE INDEX sessions_last_activity_index ON sessions (last_activity);

-- ============================================================================
-- CORE POSTING LOGIC
-- ============================================================================

-- Applies one movement to stock_balance atomically, blocking negative stock.
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

    -- Lock the balance row (or create it) before checking/adjusting, to prevent
    -- two concurrent postings from both passing the negative-stock check.
    -- ON CONFLICT targets the generated expiry_key / batch_key columns via the primary key.
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

-- Posts a stock_transaction: for every line, writes an immutable stock_movement
-- and updates stock_balance. Called by the application after status checks
-- and approval (if required) have passed. p_expiry_date / p_batch_no are NULL
-- for products that don't track expiry (stock_balance.expiry_key / batch_key
-- turn them into sentinels); p_batch_no is NULL for stock without a batch.
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


-- ============================================================================
-- SEED DATA (minimal, illustrative — roles, permissions, units)
-- ============================================================================


INSERT INTO transaction_type (code, name, requires_destination_warehouse, requires_dispatch_receive, supports_approval_threshold, display_order, posting_direction, uses_expired) VALUES
    ('OPENING_BALANCE', 'Opening Balance', FALSE, FALSE, FALSE, 1, 'IN',  FALSE),
    ('IN',              'Receiving (IN)',  FALSE, FALSE, FALSE, 2, 'IN',  FALSE),
    ('OUT',             'Delivery note (OUT)', FALSE, FALSE, FALSE, 3, 'OUT', FALSE),
    ('TRANSFER',        'Transfer',        TRUE,  TRUE,  FALSE, 4, NULL,  FALSE),
    ('DAMAGE',          'Damage',          FALSE, FALSE, TRUE,  5, 'OUT', TRUE),
    ('ADJUSTMENT',      'Adjustment',      FALSE, FALSE, TRUE,  6, NULL,  FALSE);

INSERT INTO transaction_status (code, name, is_terminal, affects_stock, display_order) VALUES
    ('DRAFT',            'Draft',             FALSE, FALSE, 1),
    ('PENDING_APPROVAL', 'Pending Approval',  FALSE, FALSE, 2),
    ('APPROVED',         'Approved',          FALSE, FALSE, 3),
    ('REJECTED',         'Rejected',          TRUE,  FALSE, 4),
    ('DISPATCHED',       'Dispatched',        FALSE, TRUE,  5),
    ('RECEIVED',         'Received',          FALSE, FALSE, 6),
    ('POSTED',           'Posted',            TRUE,  TRUE,  7),
    ('CANCELLED',        'Cancelled',         TRUE,  FALSE, 8);

INSERT INTO storage_status (code, temp_min_c, temp_max_c, display_order) VALUES
    ('AMBIENT',      15,  25, 1),
    ('COOL',          8,  15, 2),
    ('CHILLED',       2,   8, 3),
    ('FROZEN',      -25, -18, 4),
    ('DEEP_FROZEN', -40, -30, 5);

INSERT INTO role (code, name, is_system) VALUES
    ('ADMIN', 'Administrator', TRUE),
    ('MANAGER', 'Warehouse Manager', TRUE),
    ('OPERATOR', 'Warehouse Operator', TRUE),
    ('DATA_ENTRY', 'Data Entry', TRUE),
    ('AUDITOR', 'Auditor', TRUE),
    ('CUSTOMER', 'Customer (portal, future)', TRUE);

INSERT INTO unit (code, name) VALUES
    ('PC', 'Piece'),
    ('BOX', 'Box'),
    ('PLT', 'Pallet'),
    ('KG', 'Kilogram'),
    ('CTN', 'Carton');

INSERT INTO permission (code, description, module) VALUES
    ('stock.in.create', 'Create IN documents', 'STOCK'),
    ('stock.out.create', 'Create OUT documents', 'STOCK'),
    ('stock.transfer.dispatch', 'Dispatch a transfer', 'STOCK'),
    ('stock.transfer.receive', 'Receive a transfer', 'STOCK'),
    ('stock.damage.create', 'Create damage documents', 'STOCK'),
    ('stock.damage.approve', 'Approve damage documents', 'STOCK'),
    ('stock.adjustment.create', 'Create adjustment documents', 'STOCK'),
    ('stock.adjustment.approve', 'Approve adjustment documents', 'STOCK'),
    ('product.manage', 'Create/edit products', 'PRODUCT'),
    ('product.import', 'Import products from Excel', 'PRODUCT'),
    ('report.view', 'View reports', 'REPORT'),
    ('admin.users.manage', 'Manage users and roles', 'ADMIN'),
    ('admin.settings.manage', 'Manage thresholds and system settings', 'ADMIN'),
    ('audit.view', 'View audit log', 'ADMIN'),
    ('stock.opening.create', 'Create opening balance documents', 'STOCK'),
    ('stock.view', 'View stock balances and documents', 'STOCK'),
    ('masterdata.manage', 'Manage warehouses, customers and units', 'MASTERDATA'),
    ('transaction.cancel', 'Cancel stock documents', 'STOCK'),
    ('draft.in.create', 'Create and edit draft receipts', 'STOCK'),
    ('draft.in.confirm', 'Confirm draft receipts (creates the receipts)', 'STOCK'),
    ('draft.out.create', 'Create and edit orders', 'STOCK'),
    ('draft.out.confirm', 'Confirm orders (creates the delivery notes)', 'STOCK'),
    -- List, details and print pages of each type (creating / approving it opens them too).
    ('stock.in.view', 'View receipts', 'STOCK'),
    ('stock.out.view', 'View delivery notes', 'STOCK'),
    ('stock.transfer.view', 'View transfers', 'STOCK'),
    ('stock.adjustment.view', 'View adjustments', 'STOCK'),
    ('stock.damage.view', 'View damage documents', 'STOCK'),
    ('stock.opening.view', 'View opening balances', 'STOCK'),
    ('draft.in.view', 'View draft receipts', 'STOCK'),
    ('draft.out.view', 'View orders', 'STOCK'),
    -- The owner dashboard (/dashboard). Only ADMIN at first (it gets every permission).
    ('dashboard.view', 'View the owner dashboard', 'REPORT');

-- Default grants for the system roles. ADMIN gets every permission.
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM role r CROSS JOIN permission p WHERE r.code = 'ADMIN';

INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
  FROM (VALUES
    ('MANAGER', 'stock.in.create'), ('MANAGER', 'stock.out.create'),
    ('MANAGER', 'stock.transfer.dispatch'), ('MANAGER', 'stock.transfer.receive'),
    ('MANAGER', 'stock.damage.create'), ('MANAGER', 'stock.damage.approve'),
    ('MANAGER', 'stock.adjustment.create'), ('MANAGER', 'stock.adjustment.approve'),
    ('MANAGER', 'stock.opening.create'), ('MANAGER', 'stock.view'), ('MANAGER', 'report.view'),
    ('MANAGER', 'masterdata.manage'), ('MANAGER', 'product.manage'), ('MANAGER', 'product.import'),
    ('MANAGER', 'transaction.cancel'),
    ('MANAGER', 'draft.in.create'), ('MANAGER', 'draft.in.confirm'),
    ('MANAGER', 'draft.out.create'), ('MANAGER', 'draft.out.confirm'),
    ('OPERATOR', 'draft.in.create'), ('OPERATOR', 'draft.out.create'),
    ('CUSTOMER', 'draft.in.create'), ('CUSTOMER', 'draft.out.create'),
    ('MANAGER', 'draft.in.view'), ('MANAGER', 'draft.out.view'),
    ('OPERATOR', 'draft.in.view'), ('OPERATOR', 'draft.out.view'),
    ('CUSTOMER', 'draft.in.view'), ('CUSTOMER', 'draft.out.view'),
    ('OPERATOR', 'stock.in.create'), ('OPERATOR', 'stock.out.create'),
    ('OPERATOR', 'stock.damage.create'), ('OPERATOR', 'stock.adjustment.create'),
    ('OPERATOR', 'stock.transfer.dispatch'), ('OPERATOR', 'stock.transfer.receive'),
    ('OPERATOR', 'stock.view'),
    ('DATA_ENTRY', 'product.manage'), ('DATA_ENTRY', 'product.import'),
    ('DATA_ENTRY', 'masterdata.manage'), ('DATA_ENTRY', 'stock.view'),
    ('AUDITOR', 'stock.view'), ('AUDITOR', 'report.view'), ('AUDITOR', 'audit.view'),
    ('AUDITOR', 'transaction.cancel'),
    ('CUSTOMER', 'stock.view')
  ) AS g (role_code, permission_code)
  JOIN role r ON r.code = g.role_code
  JOIN permission p ON p.code = g.permission_code;

-- Each type's list: every role that sees stock and documents (stock.view).
INSERT INTO role_permission (role_id, permission_id)
SELECT rp.role_id, p.id
  FROM role_permission rp
  JOIN permission sv ON sv.id = rp.permission_id AND sv.code = 'stock.view'
  JOIN role r ON r.id = rp.role_id AND r.code <> 'ADMIN'
  CROSS JOIN permission p
 WHERE p.code IN ('stock.in.view', 'stock.out.view', 'stock.transfer.view',
                  'stock.adjustment.view', 'stock.damage.view', 'stock.opening.view');


-- ============================================================================
-- SUPABASE LOCKDOWN
-- Supabase publishes every table in the "public" schema through its Data API
-- (PostgREST) to anyone holding the project's anon key. This app does NOT use
-- that API:  talks to Postgres directly as the table owner, and table
-- owners bypass row-level security. Enabling RLS with no policies therefore
-- blocks the Data API completely while leaving Laravel unaffected.
-- Primary control: turn the Data API off in the Supabase dashboard as well.
-- This covers every table above, Laravel's own included. Any table added
-- later must also get RLS enabled; a test in the Laravel suite checks this.
-- ============================================================================

DO $$
DECLARE
    t RECORD;
BEGIN
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t.tablename);
    END LOOP;
END $$;
