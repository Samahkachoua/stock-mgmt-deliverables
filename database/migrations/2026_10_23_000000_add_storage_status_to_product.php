<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storage status per item (Ambient 15–25 °C … Deep frozen −40 to −30 °C):
     * a lookup table with each status's range, and an optional
     * product.storage_status. Existing items stay without one.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('product', 'storage_status')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE storage_status (
                code           VARCHAR(20) PRIMARY KEY,
                temp_min_c     SMALLINT NOT NULL,
                temp_max_c     SMALLINT NOT NULL,
                display_order  SMALLINT NOT NULL,
                CHECK (temp_max_c > temp_min_c)
            );

            ALTER TABLE storage_status ENABLE ROW LEVEL SECURITY;

            INSERT INTO storage_status (code, temp_min_c, temp_max_c, display_order) VALUES
                ('AMBIENT',      15,  25, 1),
                ('COOL',          8,  15, 2),
                ('CHILLED',       2,   8, 3),
                ('FROZEN',      -25, -18, 4),
                ('DEEP_FROZEN', -40, -30, 5);

            ALTER TABLE product ADD COLUMN storage_status VARCHAR(20) REFERENCES storage_status(code);
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product DROP COLUMN IF EXISTS storage_status');
        DB::statement('DROP TABLE IF EXISTS storage_status');
    }
};
