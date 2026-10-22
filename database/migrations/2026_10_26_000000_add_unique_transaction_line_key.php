<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const INDEX = 'uq_transaction_line_key';

    /**
     * CLAUDE.md rule 12, the database safety net: one line per item + unit +
     * expiry date in a document ("no expiry" counts as one value). Same as
     * schema.sql; a database created from it already has it.
     *
     * Lines that already repeat each other are reported (console and log) and
     * the index is not added, instead of failing the deploy. Merge or fix them,
     * then roll this migration back and run it again.
     */
    public function up(): void
    {
        if (DB::table('pg_indexes')->where('schemaname', 'public')->where('indexname', self::INDEX)->exists()) {
            return;
        }

        $duplicates = DB::select(<<<'SQL'
            SELECT t.doc_no, p.sku, u.code AS unit, l.expiry_date,
                   string_agg(l.line_no::text, ', ' ORDER BY l.line_no) AS line_nos
            FROM stock_transaction_line l
            JOIN stock_transaction t ON t.id = l.transaction_id
            JOIN product p ON p.id = l.product_id
            JOIN unit u ON u.id = l.unit_id
            GROUP BY t.doc_no, p.sku, u.code, l.transaction_id, l.product_id, l.unit_id, l.expiry_date
            HAVING count(*) > 1
            ORDER BY t.doc_no, p.sku
        SQL);

        if ($duplicates !== []) {
            $lines = array_map(fn ($d) => sprintf('  %s: %s (%s%s) on lines %s', $d->doc_no, $d->sku, $d->unit, $d->expiry_date ? ', expiry '.$d->expiry_date : '', $d->line_nos), $duplicates);
            $report = 'Index '.self::INDEX." NOT added: these documents repeat a line (rule 12). Fix them, then roll back and migrate again:\n".implode("\n", $lines);

            Log::warning($report);

            if (app()->runningInConsole()) {
                fwrite(STDERR, $report."\n");
            }

            return;
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON stock_transaction_line (transaction_id, product_id, unit_id, COALESCE(expiry_date, DATE '0001-01-01'))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
