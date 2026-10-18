<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Submission token of the form that created a document (CLAUDE.md "Loading
     * states"): a UUID made when the form opens. Unique, so the same form
     * submitted twice creates one document; PostingService returns the first.
     * NULL for documents not created from a form (imports).
     */
    public function up(): void
    {
        // Already in schema.sql: nothing to do on a database created from it.
        if (Schema::hasColumn('stock_transaction', 'client_request_id')) {
            return;
        }

        DB::statement('ALTER TABLE stock_transaction ADD COLUMN client_request_id UUID');
        DB::statement('ALTER TABLE stock_transaction ADD CONSTRAINT uq_stock_transaction_client_request UNIQUE (client_request_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_transaction DROP CONSTRAINT IF EXISTS uq_stock_transaction_client_request');
        DB::statement('ALTER TABLE stock_transaction DROP COLUMN IF EXISTS client_request_id');
    }
};
