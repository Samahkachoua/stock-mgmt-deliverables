<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a user who can confirm orders gets an email when an order
     * in their warehouses is added, changed or cancelled (Settings › Users,
     * "Email about orders"). On for everyone at first; it only matters with
     * the confirm permission.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('app_user', 'notify_new_orders')) {
            return;
        }

        DB::statement('ALTER TABLE app_user ADD COLUMN notify_new_orders BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS notify_new_orders');
    }
};
