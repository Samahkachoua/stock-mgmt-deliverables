<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an order must be delivered and how urgent it is (Urgent / Normal /
     * Low). Orders only: draft receipts leave both empty. Existing orders get
     * their order date and Normal.
     *
     * Same as schema.sql; a database created from it already has the columns.
     */
    public function up(): void
    {
        if (Schema::hasColumn('stock_draft', 'delivery_date')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_draft
                ADD COLUMN delivery_date DATE,
                ADD COLUMN priority VARCHAR(10)
                    CONSTRAINT ck_stock_draft_priority CHECK (priority IN ('URGENT', 'NORMAL', 'LOW'));

            UPDATE stock_draft SET delivery_date = document_date, priority = 'NORMAL' WHERE transaction_type = 'OUT';

            ALTER TABLE stock_draft
                ADD CONSTRAINT ck_stock_draft_delivery
                    CHECK (transaction_type <> 'OUT' OR (delivery_date IS NOT NULL AND priority IS NOT NULL)),
                ADD CONSTRAINT ck_stock_draft_delivery_date
                    CHECK (delivery_date IS NULL OR delivery_date >= document_date);
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_draft DROP COLUMN IF EXISTS delivery_date, DROP COLUMN IF EXISTS priority');
    }
};
