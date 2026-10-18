<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A database built by running schema.sql by hand (the Supabase SQL editor)
 * has every table but an empty migrations table, so the first deploy runs
 * every migration on it. Each one must find its change there and skip.
 */
it('runs every migration again on a database built from schema.sql', function () {
    $before = DB::table('role_permission')->count();

    foreach (glob(database_path('migrations/*.php')) as $file) {
        $migration = require $file;

        expect($migration)->toBeInstanceOf(Migration::class);
        $migration->up();
    }

    expect(DB::table('role_permission')->count())->toBe($before);
});
