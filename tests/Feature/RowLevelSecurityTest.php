<?php

use Illuminate\Support\Facades\DB;

/*
 * Supabase exposes every table in `public` through its Data API. RLS with no
 * policies blocks that API while Laravel (the table owner) is unaffected, so
 * every table — including Laravel's own — must have RLS enabled.
 */
it('has row level security enabled on every public table', function () {
    $tables = collect(DB::select(
        "SELECT tablename, rowsecurity FROM pg_tables WHERE schemaname = 'public'"
    ));

    // Guard against passing vacuously on an empty or half-migrated database.
    expect($tables->pluck('tablename'))
        ->toContain('migrations', 'app_user', 'stock_movement', 'sessions', 'cache', 'jobs');

    $withoutRls = $tables->reject(fn ($table) => $table->rowsecurity)->pluck('tablename')->all();

    expect($withoutRls)->toBe([], 'Tables without RLS: '.implode(', ', $withoutRls));
});
