<?php

use Illuminate\Support\Facades\DB;

/*
 * No batch numbers (migration 2026_10_16_000000): stock of an expiry item is
 * kept per expiry date, stored on the lines, movements and balances themselves.
 */
it('has no batch table and no batch columns left', function () {
    $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename');
    // Laravel's own migrations.batch and job_batches are not stock batches.
    $batchColumns = collect(DB::select(
        "SELECT table_name, column_name FROM information_schema.columns
          WHERE table_schema = 'public' AND column_name LIKE 'batch%' AND table_name NOT IN ('migrations', 'job_batches')"
    ))->map(fn ($c) => $c->table_name.'.'.$c->column_name)->all();

    expect($tables)->toContain('stock_balance')->not->toContain('batch')
        ->and($batchColumns)->toBe([]);

    foreach (['stock_transaction_line', 'stock_movement', 'stock_balance', 'in_transit_balance'] as $table) {
        expect(DB::selectOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = 'expiry_date'",
            [$table],
        )?->data_type)->toBe('date');
    }
});
