<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A password typed by an administrator (Settings › Users) is temporary: the
     * user must choose their own at the next sign-in (EnsurePasswordChanged).
     * Same as schema.sql; a database created from it already has it.
     */
    public function up(): void
    {
        if (Schema::hasColumn('app_user', 'must_change_password')) {
            return;
        }

        DB::statement('ALTER TABLE app_user ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS must_change_password');
    }
};
