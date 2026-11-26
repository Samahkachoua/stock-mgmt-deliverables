<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications (App\Support\Notifications\Notifier) and the two
     * new email settings on app_user (approval requests, approval results).
     * Same as schema.sql; a database created from it already has them.
     */
    public function up(): void
    {
        if (Schema::hasTable('notification')) {
            return;
        }

        DB::statement('ALTER TABLE app_user ADD COLUMN IF NOT EXISTS notify_approvals BOOLEAN NOT NULL DEFAULT TRUE');
        DB::statement('ALTER TABLE app_user ADD COLUMN IF NOT EXISTS notify_approval_results BOOLEAN NOT NULL DEFAULT TRUE');

        DB::unprepared(<<<'SQL'
            CREATE TABLE notification (
                id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id      BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
                kind         VARCHAR(10) NOT NULL CHECK (kind IN ('ACTION', 'INFO')),
                event        VARCHAR(40) NOT NULL,
                entity       VARCHAR(30) NOT NULL CHECK (entity IN ('stock_transaction', 'stock_draft')),
                entity_id    BIGINT NOT NULL,
                actor_id     BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
                params       JSONB NOT NULL DEFAULT '{}'::jsonb,
                read_at      TIMESTAMPTZ,
                resolved_at  TIMESTAMPTZ,
                resolved_by  BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
                outcome      VARCHAR(20),
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE INDEX idx_notification_user ON notification (user_id, updated_at DESC, id DESC);
            CREATE INDEX idx_notification_unread ON notification (user_id) WHERE read_at IS NULL AND resolved_at IS NULL;
            CREATE INDEX idx_notification_open_entity ON notification (entity, entity_id) WHERE resolved_at IS NULL;
            CREATE UNIQUE INDEX uq_notification_open ON notification (user_id, entity, entity_id) WHERE kind = 'ACTION' AND resolved_at IS NULL;
            CREATE INDEX idx_notification_created ON notification (created_at);
            SQL);

        DB::statement('ALTER TABLE notification ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notification');
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS notify_approvals');
        DB::statement('ALTER TABLE app_user DROP COLUMN IF EXISTS notify_approval_results');
    }
};
