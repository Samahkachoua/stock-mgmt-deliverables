<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<array{code: string, description: string, module: string}>
     */
    private const PERMISSIONS = [
        ['code' => 'draft.in.create', 'description' => 'Create and edit draft receipts', 'module' => 'STOCK'],
        ['code' => 'draft.in.confirm', 'description' => 'Confirm draft receipts (creates the receipts)', 'module' => 'STOCK'],
        ['code' => 'draft.out.create', 'description' => 'Create and edit draft issues', 'module' => 'STOCK'],
        ['code' => 'draft.out.confirm', 'description' => 'Confirm draft issues (creates the issues)', 'module' => 'STOCK'],
    ];

    /**
     * Default grants. ADMIN is not listed: it receives every new permission.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'MANAGER' => ['draft.in.create', 'draft.in.confirm', 'draft.out.create', 'draft.out.confirm'],
        'OPERATOR' => ['draft.in.create', 'draft.out.create'],
        'CUSTOMER' => ['draft.in.create', 'draft.out.create'],
    ];

    /**
     * Customer branches, customer users and drafts:
     *
     * - customer_branch, and user_customer_branch (optional branches of a customer user);
     * - app_user.is_customer_user goes: a user with a customer_id IS a customer user;
     * - stock_draft / stock_draft_line: draft receipts and issues, which never
     *   touch stock; confirming one creates one posted receipt / issue per
     *   warehouse, linked by stock_transaction.draft_id (and customer_branch_id);
     * - reference sequences, permissions and their default grants.
     *
     * Same as schema.sql; a database created from it already has all of this.
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_draft')) {
            // Built from schema.sql: the permissions are there, but a permission
            // map cached before may not have them yet.
            Permissions::flush();

            return;
        }

        // No staff user may become a customer user when the flag goes.
        if (Schema::hasColumn('app_user', 'is_customer_user')) {
            DB::statement('UPDATE app_user SET customer_id = NULL WHERE NOT is_customer_user');
            DB::statement('ALTER TABLE app_user DROP COLUMN is_customer_user');
        }

        DB::unprepared(<<<'SQL'
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
                UNIQUE (id, customer_id)
            );

            CREATE TABLE user_customer_branch (
                user_id   BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
                branch_id BIGINT NOT NULL REFERENCES customer_branch(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, branch_id)
            );

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

            CREATE SEQUENCE IF NOT EXISTS doc_no_draft_in_seq AS BIGINT START 1;
            CREATE SEQUENCE IF NOT EXISTS doc_no_draft_out_seq AS BIGINT START 1;

            CREATE TABLE stock_draft (
                id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                doc_no             VARCHAR(30) NOT NULL UNIQUE,
                transaction_type   VARCHAR(30) NOT NULL REFERENCES transaction_type(code),
                status             VARCHAR(30) NOT NULL DEFAULT 'DRAFT' REFERENCES transaction_status(code),
                customer_id        BIGINT NOT NULL REFERENCES customer(id),
                customer_branch_id BIGINT NOT NULL,
                document_date      DATE NOT NULL DEFAULT CURRENT_DATE,
                reference_note     TEXT,
                client_request_id  UUID UNIQUE,
                created_by         BIGINT NOT NULL REFERENCES app_user(id),
                updated_by         BIGINT REFERENCES app_user(id),
                confirmed_by       BIGINT REFERENCES app_user(id),
                confirmed_at       TIMESTAMPTZ,
                cancel_reason      TEXT,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT fk_stock_draft_branch FOREIGN KEY (customer_branch_id, customer_id)
                    REFERENCES customer_branch (id, customer_id)
            );

            CREATE INDEX idx_stock_draft_list ON stock_draft (transaction_type, customer_id, document_date DESC);

            CREATE TABLE stock_draft_line (
                id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                draft_id     BIGINT NOT NULL REFERENCES stock_draft(id) ON DELETE CASCADE,
                line_no      INT NOT NULL,
                warehouse_id BIGINT NOT NULL REFERENCES warehouse(id),
                product_id   BIGINT NOT NULL REFERENCES product(id),
                unit_id      BIGINT NOT NULL REFERENCES unit(id),
                qty_entered  NUMERIC(18,4) NOT NULL CHECK (qty_entered > 0),
                qty_base     NUMERIC(18,4) NOT NULL CHECK (qty_base > 0),
                expiry_date  DATE,
                serials      JSONB NOT NULL DEFAULT '[]'::jsonb,
                UNIQUE (draft_id, line_no)
            );

            CREATE INDEX idx_stock_draft_line_warehouse ON stock_draft_line (warehouse_id, draft_id);

            ALTER TABLE stock_transaction
                ADD COLUMN customer_branch_id BIGINT REFERENCES customer_branch(id),
                ADD COLUMN draft_id BIGINT,
                ADD CONSTRAINT fk_stock_transaction_draft FOREIGN KEY (draft_id) REFERENCES stock_draft(id);

            CREATE INDEX idx_stock_transaction_draft ON stock_transaction (draft_id);

            ALTER TABLE customer_branch ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_customer_branch ENABLE ROW LEVEL SECURITY;
            ALTER TABLE stock_draft ENABLE ROW LEVEL SECURITY;
            ALTER TABLE stock_draft_line ENABLE ROW LEVEL SECURITY;
            SQL);

        DB::table('permission')->insertOrIgnore(self::PERMISSIONS);

        $permissions = DB::table('permission')->whereIn('code', array_column(self::PERMISSIONS, 'code'))->pluck('id', 'code');
        $roles = DB::table('role')->whereIn('code', ['ADMIN', ...array_keys(self::GRANTS)])->pluck('id', 'code');
        $rows = [];

        foreach ($permissions as $permissionId) {
            if (isset($roles['ADMIN'])) {
                $rows[] = ['role_id' => $roles['ADMIN'], 'permission_id' => $permissionId];
            }
        }

        foreach (self::GRANTS as $role => $codes) {
            foreach ($codes as $code) {
                if (isset($roles[$role])) {
                    $rows[] = ['role_id' => $roles[$role], 'permission_id' => $permissions[$code]];
                }
            }
        }

        DB::table('role_permission')->insertOrIgnore($rows);

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->whereIn('code', array_column(self::PERMISSIONS, 'code'))->delete();

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_transaction
                DROP CONSTRAINT IF EXISTS fk_stock_transaction_draft,
                DROP COLUMN IF EXISTS draft_id,
                DROP COLUMN IF EXISTS customer_branch_id;

            DROP TABLE IF EXISTS stock_draft_line, stock_draft, user_customer_branch, customer_branch;
            DROP FUNCTION IF EXISTS fn_check_user_branch CASCADE;
            DROP SEQUENCE IF EXISTS doc_no_draft_in_seq, doc_no_draft_out_seq;

            ALTER TABLE app_user ADD COLUMN IF NOT EXISTS is_customer_user BOOLEAN NOT NULL DEFAULT FALSE;
            UPDATE app_user SET is_customer_user = (customer_id IS NOT NULL);
            SQL);

        Permissions::flush();
    }
};
