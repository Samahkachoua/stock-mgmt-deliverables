<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Load the stock schema (tables, constraints, triggers and posting functions).
     * schema.sql describes the whole current database, so on a new database
     * the later migrations find their changes already there and do nothing.
     *
     * schema.sql has no BEGIN/COMMIT on purpose: Laravel already wraps this
     * migration in a transaction. It also enables RLS on every public table
     * that exists when it runs, including Laravel's own `migrations` table.
     */
    public function up(): void
    {
        // Already loaded (e.g. schema.sql run by hand in the Supabase SQL editor,
        // which leaves the migrations table empty): nothing to do.
        if (Schema::hasTable('app_user')) {
            return;
        }

        DB::unprepared(file_get_contents(database_path('sql/schema.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS
                translation, import_row, import_job, audit_log, approval,
                in_transit_balance, stock_balance, stock_movement,
                stock_transaction_line_serial, stock_transaction_line, stock_transaction,
                approval_threshold, transaction_status, transaction_type,
                serial_number, product_barcode, product_unit, product, unit,
                customer, user_warehouse, warehouse, user_role, role_permission,
                permission, role, app_user,
                cache, cache_locks, jobs, job_batches, failed_jobs, password_reset_tokens, sessions
            CASCADE;

            DROP SEQUENCE IF EXISTS
                doc_no_opening_balance_seq, doc_no_in_seq, doc_no_out_seq,
                doc_no_transfer_seq, doc_no_adjustment_seq, doc_no_damage_seq;

            DROP FUNCTION IF EXISTS
                fn_validate_product_unit, fn_apply_balance, fn_post_movement, fn_resolve_threshold
            CASCADE;

            DROP TYPE IF EXISTS
                movement_direction, approval_scope, approval_decision,
                serial_status, adjustment_reason, damage_reason
            CASCADE;
            SQL);
    }
};
