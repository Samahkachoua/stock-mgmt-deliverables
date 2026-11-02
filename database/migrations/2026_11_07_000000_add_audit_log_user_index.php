<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Home's "Pick up where you left off" (App\Support\Home\RecentActivity)
     * reads one user's newest audit rows.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log (user_id, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_audit_user');
    }
};
