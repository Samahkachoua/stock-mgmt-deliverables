<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Login history: every sign-in, successful or not (App\Support\Auth\LoginLog),
     * and its report permission (ADMIN and AUDITOR, like the audit log).
     * Same as schema.sql; a database created from it already has both.
     */
    public function up(): void
    {
        if (! Schema::hasTable('login_log')) {
            DB::unprepared(<<<'SQL'
                CREATE TABLE login_log (
                    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                    user_id     BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
                    email       VARCHAR(255) NOT NULL,
                    result      VARCHAR(20) NOT NULL
                                CHECK (result IN ('SUCCESS', 'WRONG_PASSWORD', 'UNKNOWN_EMAIL', 'INACTIVE', 'THROTTLED')),
                    ip_address  VARCHAR(45) NOT NULL,
                    user_agent  VARCHAR(500),
                    remember    BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
                );
                CREATE INDEX idx_login_log_created ON login_log (created_at DESC, id DESC);
                CREATE INDEX idx_login_log_user ON login_log (user_id, id DESC);
                CREATE INDEX idx_login_log_ip ON login_log (ip_address, created_at DESC);
                ALTER TABLE login_log ENABLE ROW LEVEL SECURITY;
                SQL);
        }

        if (! DB::table('permission')->where('code', 'report.login_history.view')->exists()) {
            $permissionId = DB::table('permission')->insertGetId(
                ['code' => 'report.login_history.view', 'description' => 'View the login history', 'module' => 'REPORT'],
                'id',
            );

            $roles = DB::table('role')->whereIn('code', ['ADMIN', 'AUDITOR'])->pluck('id');

            DB::table('role_permission')->insertOrIgnore($roles->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permissionId])->values()->all());
        }

        Permissions::flush();
    }

    public function down(): void
    {
        DB::table('permission')->where('code', 'report.login_history.view')->delete();
        DB::statement('DROP TABLE IF EXISTS login_log');

        Permissions::flush();
    }
};
