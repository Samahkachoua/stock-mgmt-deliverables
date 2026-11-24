<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The warehouse that serves a branch (Settings › Branches). An Order or a
     * Draft receipt for the branch starts on it. Required in the form, but
     * nullable here: branches created before it have none until they are next
     * edited.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('customer_branch', 'warehouse_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_branch ADD COLUMN warehouse_id BIGINT REFERENCES warehouse(id);

            CREATE INDEX ix_customer_branch_warehouse ON customer_branch (warehouse_id);
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_customer_branch_warehouse');
        DB::statement('ALTER TABLE customer_branch DROP COLUMN IF EXISTS warehouse_id');
    }
};
