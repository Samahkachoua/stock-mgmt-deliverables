<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a transfer line arrived short (qty_received < qty_base). Required for
     * short lines when the receipt is confirmed; NULL otherwise. The codes are
     * labelled in lang/<locale>/transfers.php.
     */
    public function up(): void
    {
        // Already in schema.sql: nothing to do on a database created from it.
        if (Schema::hasColumn('stock_transaction_line', 'shortage_reason')) {
            return;
        }

        DB::statement('ALTER TABLE stock_transaction_line ADD COLUMN shortage_reason VARCHAR(30)');
        DB::statement(<<<'SQL'
            ALTER TABLE stock_transaction_line ADD CONSTRAINT chk_shortage_reason
                CHECK (shortage_reason IS NULL OR shortage_reason IN ('DAMAGED_IN_TRANSIT', 'MISSING', 'COUNTING_ERROR'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_transaction_line DROP CONSTRAINT IF EXISTS chk_shortage_reason');
        DB::statement('ALTER TABLE stock_transaction_line DROP COLUMN IF EXISTS shortage_reason');
    }
};
