<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Indexes for queries that grow with the ledger (docs/performance.md):
     *
     *   stock_movement(line_id)      receiving / cancelling reads a line's movements,
     *                                inside the posting transaction (was a full scan)
     *   audit_log(created_at)        the audit log report: newest first, by period
     *   approval(transaction_id)     pending approvals, approver shown on reports
     *
     * Plain CREATE INDEX (migrations run in a transaction): on a large production
     * table it briefly blocks writes to that table; run it outside working hours.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_movement_line ON stock_movement (line_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_approval_transaction ON approval (transaction_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_approval_transaction');
        DB::statement('DROP INDEX IF EXISTS idx_audit_created');
        DB::statement('DROP INDEX IF EXISTS idx_movement_line');
    }
};
