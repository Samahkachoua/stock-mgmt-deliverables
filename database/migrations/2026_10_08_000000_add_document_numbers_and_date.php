<?php

use App\Support\Transactions\TransactionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference numbers: one sequence per transaction type, read with nextval()
     * inside the posting transaction (App\Services\Stock\DocumentNumbers), so
     * two documents never get the same number. Sequences are not transactional:
     * a rolled-back save leaves a gap in the numbering.
     *
     * document_date: the date the user gives the document (defaults to today;
     * an Opening Balance may be dated back). schema.sql only had created_at /
     * posted_at, which are when it was recorded, not what it is dated.
     */
    private const TYPES = ['OPENING_BALANCE', 'IN', 'OUT', 'TRANSFER', 'ADJUSTMENT', 'DAMAGE'];

    public function up(): void
    {
        // Already in schema.sql: nothing to do on a database created from it.
        if (Schema::hasColumn('stock_transaction', 'document_date')) {
            return;
        }

        foreach (self::TYPES as $type) {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::sequence($type).' AS BIGINT START 1');
        }

        DB::statement('ALTER TABLE stock_transaction ADD COLUMN document_date DATE NOT NULL DEFAULT CURRENT_DATE');
        DB::statement('CREATE INDEX idx_stock_transaction_list ON stock_transaction (warehouse_id, transaction_type, document_date DESC)');

        // What a type does to stock, as data (CLAUDE.md rule 4: no switch over type codes in PHP).
        //   posting_direction: IN = adds stock, OUT = takes it; NULL = decided per line
        //                      (Adjustment) or in two steps (Transfer).
        //   uses_expired:      may take stock from expired batches (Damage writes them off);
        //                      otherwise expired batches are skipped.
        DB::statement('ALTER TABLE transaction_type ADD COLUMN posting_direction movement_direction');
        DB::statement('ALTER TABLE transaction_type ADD COLUMN uses_expired BOOLEAN NOT NULL DEFAULT FALSE');
        DB::table('transaction_type')->whereIn('code', ['OPENING_BALANCE', 'IN'])->update(['posting_direction' => 'IN']);
        DB::table('transaction_type')->whereIn('code', ['OUT', 'DAMAGE'])->update(['posting_direction' => 'OUT']);
        DB::table('transaction_type')->where('code', 'DAMAGE')->update(['uses_expired' => true]);

        TransactionTypes::flush();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_type DROP COLUMN IF EXISTS uses_expired');
        DB::statement('ALTER TABLE transaction_type DROP COLUMN IF EXISTS posting_direction');
        DB::statement('DROP INDEX IF EXISTS idx_stock_transaction_list');
        DB::statement('ALTER TABLE stock_transaction DROP COLUMN IF EXISTS document_date');

        foreach (self::TYPES as $type) {
            DB::statement('DROP SEQUENCE IF EXISTS '.self::sequence($type));
        }

        TransactionTypes::flush();
    }

    /**
     * Same naming as App\Services\Stock\DocumentNumbers::sequence().
     */
    private static function sequence(string $type): string
    {
        return 'doc_no_'.strtolower($type).'_seq';
    }
};
