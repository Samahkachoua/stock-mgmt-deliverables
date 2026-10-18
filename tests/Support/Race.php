<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * Runs two worker processes, each with its own database connection, and lets
 * them start at the same moment: both wait on an advisory lock this process
 * holds, which is released only once both are queued behind it.
 */
final class Race
{
    /**
     * @param  array<string, mixed>  $job  passed to each worker as JSON; 'barrier' is added
     * @return array<int, array<string, mixed>> each worker's JSON output
     */
    public static function run(string $worker, array $job): array
    {
        $barrier = random_int(1, 2_000_000_000);
        $payload = json_encode([...$job, 'barrier' => $barrier], JSON_THROW_ON_ERROR);

        DB::select('SELECT pg_advisory_lock(?)', [$barrier]);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];

        $workers = [];
        foreach ([1, 2] as $n) {
            $workers[$n] = new Process([PHP_BINARY, $worker, $payload], base_path(), $env, timeout: 60);
            $workers[$n]->start();
        }

        try {
            $waiting = 0;
            for ($i = 0; $i < 400 && $waiting < 2; $i++) {
                usleep(50_000);
                $waiting = (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted", [$barrier])->n;
            }

            Assert::assertSame(2, $waiting, 'Both workers should be waiting at the start line');
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
        }

        $results = [];
        foreach ($workers as $n => $process) {
            $process->wait();
            Assert::assertTrue($process->isSuccessful(), "Worker $n failed: ".$process->getErrorOutput());
            $results[$n] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
