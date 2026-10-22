<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's default warehouse: where forms, lists and reports start
     * (there is no warehouse selector in the top bar). Existing users get the
     * warehouse they used to start in: their first assigned active one by name.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('app_user', 'default_warehouse_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE app_user
                ADD COLUMN default_warehouse_id BIGINT NULL,
                ADD CONSTRAINT fk_user_default_warehouse FOREIGN KEY (default_warehouse_id) REFERENCES warehouse(id);

            UPDATE app_user u
               SET default_warehouse_id = (
                   SELECT w.id
                     FROM user_warehouse uw
                     JOIN warehouse w ON w.id = uw.warehouse_id
                    WHERE uw.user_id = u.id AND w.active
                    ORDER BY w.name, w.id
                    LIMIT 1
               );
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_user DROP CONSTRAINT IF EXISTS fk_user_default_warehouse');
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS default_warehouse_id');
    }
};
