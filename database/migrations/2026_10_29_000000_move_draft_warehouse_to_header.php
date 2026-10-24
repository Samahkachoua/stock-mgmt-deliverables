<?php

use App\Services\Stock\DocumentNumbers;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A draft receipt / order now has one warehouse, in its header
     * (stock_draft.warehouse_id), instead of one per line; confirming creates
     * one receipt / delivery note.
     *
     * An open draft with lines in several warehouses is split into one draft
     * per warehouse: it keeps its number for the warehouse of its first line,
     * the others get new numbers. Confirmed and cancelled drafts take the
     * warehouse of their first line; the documents they created keep their own.
     *
     * Same as schema.sql; a database created from it already has the column.
     */
    public function up(): void
    {
        if (Schema::hasColumn('stock_draft', 'warehouse_id')) {
            return;
        }

        DB::statement('ALTER TABLE stock_draft ADD COLUMN warehouse_id BIGINT REFERENCES warehouse(id)');

        $this->splitOpenDrafts();

        DB::statement('UPDATE stock_draft d SET warehouse_id = (
            SELECT l.warehouse_id FROM stock_draft_line l WHERE l.draft_id = d.id ORDER BY l.line_no LIMIT 1
        )');

        // A draft without lines (not possible through the app): its creator's default warehouse, or any.
        DB::statement('UPDATE stock_draft d SET warehouse_id = COALESCE(
            (SELECT u.default_warehouse_id FROM app_user u WHERE u.id = d.created_by),
            (SELECT min(w.id) FROM warehouse w)
        ) WHERE warehouse_id IS NULL');

        DB::statement('ALTER TABLE stock_draft ALTER COLUMN warehouse_id SET NOT NULL');
        DB::statement('CREATE INDEX idx_stock_draft_warehouse ON stock_draft (warehouse_id, transaction_type)');
        DB::statement('DROP INDEX IF EXISTS idx_stock_draft_line_warehouse');
        DB::statement('ALTER TABLE stock_draft_line DROP COLUMN warehouse_id');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_draft_line ADD COLUMN warehouse_id BIGINT REFERENCES warehouse(id)');
        DB::statement('UPDATE stock_draft_line l SET warehouse_id = d.warehouse_id FROM stock_draft d WHERE d.id = l.draft_id');
        DB::statement('ALTER TABLE stock_draft_line ALTER COLUMN warehouse_id SET NOT NULL');
        DB::statement('CREATE INDEX idx_stock_draft_line_warehouse ON stock_draft_line (warehouse_id, draft_id)');
        DB::statement('DROP INDEX IF EXISTS idx_stock_draft_warehouse');
        DB::statement('ALTER TABLE stock_draft DROP COLUMN warehouse_id');
    }

    /**
     * One draft per warehouse for open drafts whose lines are in several.
     */
    private function splitOpenDrafts(): void
    {
        $drafts = DB::table('stock_draft as d')
            ->where('d.status', 'DRAFT')
            ->whereRaw('(SELECT count(DISTINCT l.warehouse_id) FROM stock_draft_line l WHERE l.draft_id = d.id) > 1')
            ->orderBy('d.id')
            ->get();
        $numbers = app(DocumentNumbers::class);
        $touched = [];

        foreach ($drafts as $draft) {
            $warehouses = DB::table('stock_draft_line')
                ->where('draft_id', $draft->id)
                ->groupBy('warehouse_id')
                ->orderByRaw('min(line_no)')
                ->pluck('warehouse_id')
                ->all();
            $touched[] = $draft->id;
            $created = [];

            // The first line's warehouse stays on this draft.
            foreach (array_slice($warehouses, 1) as $warehouseId) {
                $reference = $numbers->nextDraft(DraftTypes::get($draft->transaction_type), CompanyTime::now()->year);
                $id = (string) DB::table('stock_draft')->insertGetId([
                    'doc_no' => $reference,
                    'transaction_type' => $draft->transaction_type,
                    'status' => 'DRAFT',
                    'customer_id' => $draft->customer_id,
                    'customer_branch_id' => $draft->customer_branch_id,
                    'document_date' => $draft->document_date,
                    'reference_note' => $draft->reference_note,
                    'created_by' => $draft->created_by,
                    'updated_by' => $draft->updated_by,
                    'created_at' => $draft->created_at,
                    'updated_at' => now(),
                    'warehouse_id' => $warehouseId,
                ], 'id');

                DB::table('stock_draft_line')->where('draft_id', $draft->id)->where('warehouse_id', $warehouseId)->update(['draft_id' => $id]);
                DB::table('audit_log')->insert([
                    'entity' => 'stock_draft',
                    'entity_id' => $id,
                    'action' => 'CREATE',
                    'after_data' => json_encode(['doc_no' => $reference, 'split_from' => $draft->doc_no]),
                ]);

                $touched[] = $id;
                $created[] = $reference;
            }

            DB::table('stock_draft')->where('id', $draft->id)->update(['updated_at' => now()]);
            DB::table('audit_log')->insert([
                'entity' => 'stock_draft',
                'entity_id' => $draft->id,
                'action' => 'UPDATE',
                'after_data' => json_encode(['doc_no' => $draft->doc_no, 'split_into' => $created]),
            ]);
        }

        if ($touched === []) {
            return;
        }

        // Lines numbered 1, 2, 3… again in each draft (out of the way first: line_no is unique per draft).
        DB::table('stock_draft_line')->whereIn('draft_id', $touched)->update(['line_no' => DB::raw('line_no + 1000000')]);
        DB::statement('UPDATE stock_draft_line l SET line_no = r.n FROM (
            SELECT id, row_number() OVER (PARTITION BY draft_id ORDER BY line_no) AS n FROM stock_draft_line WHERE draft_id = ANY(?::bigint[])
        ) r WHERE l.id = r.id', ['{'.implode(',', $touched).'}']);
    }
};
