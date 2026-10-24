<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a user who can confirm draft receipts gets an email when a draft
     * receipt in their warehouses is added, changed or cancelled (Settings ›
     * Users, "Email about draft receipts"). On for everyone at first, like the
     * order emails; it only matters with the confirm permission.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('app_user', 'notify_draft_receipts')) {
            return;
        }

        DB::statement('ALTER TABLE app_user ADD COLUMN notify_draft_receipts BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS notify_draft_receipts');
    }
};
