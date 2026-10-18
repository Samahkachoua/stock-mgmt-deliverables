<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order movements were posted in. created_at is the posting
     * transaction's start (now()), shared by every movement of a document and
     * by documents posted in one transaction; ids are random UUIDs. The stock
     * card and "balance after" need a strict order, so the ledger gets a
     * sequence, assigned as each row is inserted by fn_post_movement.
     *
     * Existing rows are numbered by created_at (ties in no particular order).
     * No new table, so nothing to add to row-level security.
     */
    public function up(): void
    {
        // Already in schema.sql: nothing to do on a database created from it.
        if (Schema::hasColumn('stock_movement', 'seq')) {
            return;
        }

        DB::statement('CREATE SEQUENCE stock_movement_seq_seq AS BIGINT');
        DB::statement('ALTER TABLE stock_movement ADD COLUMN seq BIGINT');
        DB::statement(<<<'SQL'
            UPDATE stock_movement m SET seq = numbered.n
              FROM (SELECT id, nextval('stock_movement_seq_seq') AS n
                      FROM (SELECT id FROM stock_movement ORDER BY created_at, id) ordered) numbered
             WHERE numbered.id = m.id
        SQL);
        DB::statement("ALTER TABLE stock_movement ALTER COLUMN seq SET DEFAULT nextval('stock_movement_seq_seq')");
        DB::statement('ALTER TABLE stock_movement ALTER COLUMN seq SET NOT NULL');
        DB::statement('ALTER SEQUENCE stock_movement_seq_seq OWNED BY stock_movement.seq');
        DB::statement('CREATE UNIQUE INDEX uq_stock_movement_seq ON stock_movement (seq)');
        DB::statement('CREATE INDEX idx_movement_card ON stock_movement (product_id, warehouse_id, seq)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_movement_card');
        DB::statement('DROP INDEX IF EXISTS uq_stock_movement_seq');
        DB::statement('ALTER TABLE stock_movement DROP COLUMN IF EXISTS seq');
        DB::statement('DROP SEQUENCE IF EXISTS stock_movement_seq_seq');
    }
};
