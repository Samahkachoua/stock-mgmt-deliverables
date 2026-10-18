<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the staged import (App\Imports\ImportEngine) needs beyond schema.sql:
     * where the uploaded file is kept, reading progress, why a file was refused,
     * the column headers as uploaded (for the error report), the warehouses an
     * opening-balance file touches (who may see the job), and what was created.
     *
     * import_job.status: UPLOADED (queued / being read) → VALIDATED → CONFIRMED,
     * or FAILED (unreadable, wrong template, too many rows).
     * import_row.status: PENDING → VALID | WARNING | ERROR → IMPORTED.
     *
     * No new table, so nothing to add to row-level security.
     */
    public function up(): void
    {
        // Already in schema.sql: nothing to do on a database created from it.
        if (Schema::hasColumn('import_job', 'disk')) {
            return;
        }

        DB::statement('ALTER TABLE import_job ADD COLUMN disk VARCHAR(50)');
        DB::statement('ALTER TABLE import_job ADD COLUMN path VARCHAR(500)');
        DB::statement('ALTER TABLE import_job ADD COLUMN total_rows INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE import_job ADD COLUMN processed_rows INT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE import_job ADD COLUMN error_message TEXT');
        DB::statement('ALTER TABLE import_job ADD COLUMN headers JSONB');
        DB::statement('ALTER TABLE import_job ADD COLUMN warehouse_ids JSONB');
        DB::statement('ALTER TABLE import_job ADD COLUMN result JSONB');
        DB::statement('ALTER TABLE import_job ADD COLUMN confirmed_by BIGINT REFERENCES app_user(id)');
        DB::statement('CREATE INDEX idx_import_job_created ON import_job (job_type, created_at DESC)');
        DB::statement('CREATE INDEX idx_import_row_status ON import_row (job_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_import_row_status');
        DB::statement('DROP INDEX IF EXISTS idx_import_job_created');

        foreach (['confirmed_by', 'result', 'warehouse_ids', 'headers', 'error_message', 'processed_rows', 'total_rows', 'path', 'disk'] as $column) {
            DB::statement("ALTER TABLE import_job DROP COLUMN IF EXISTS $column");
        }
    }
};
