<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The email of a branch's manager (Settings › Branches). Required in the
     * form, but nullable here: branches created before it have none until they
     * are next edited. One branch per customer per email (any case).
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('customer_branch', 'manager_email')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE customer_branch ADD COLUMN manager_email VARCHAR(320);

            CREATE UNIQUE INDEX uq_customer_branch_manager_email
                ON customer_branch (customer_id, lower(manager_email)) WHERE manager_email IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_customer_branch_manager_email');
        DB::statement('ALTER TABLE customer_branch DROP COLUMN IF EXISTS manager_email');
    }
};
