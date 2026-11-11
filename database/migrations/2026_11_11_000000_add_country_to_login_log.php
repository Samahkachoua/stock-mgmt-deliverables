<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The country of each sign-in (ISO code, App\Support\Auth\IpCountry).
     * Same as schema.sql; a database created from it already has it.
     */
    public function up(): void
    {
        if (Schema::hasColumn('login_log', 'country')) {
            return;
        }

        DB::statement("ALTER TABLE login_log ADD COLUMN country CHAR(2) CHECK (country ~ '^[A-Z]{2}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE login_log DROP COLUMN IF EXISTS country');
    }
};
