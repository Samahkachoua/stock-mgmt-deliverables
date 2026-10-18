<?php

/*
 * Child process for ConcurrentReceiveTest: boots the app on its own database
 * connection, waits at the start line (a shared advisory lock the test holds
 * exclusively), then confirms receipt of the whole transfer. Prints one line of JSON.
 *
 *   php receive-worker.php '{"barrier":123,"transfer":"...","line":"...","qty":10,"user":"..."}'
 */

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Services\Stock\ReceivedLine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$job = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

// Start line: blocks until the test releases its exclusive lock.
DB::select('SELECT pg_advisory_lock_shared(?)', [$job['barrier']]);
DB::select('SELECT pg_advisory_unlock_shared(?)', [$job['barrier']]);

try {
    app(PostingService::class)->receive(
        StockTransaction::withoutGlobalScopes()->findOrFail($job['transfer']),
        [$job['line'] => new ReceivedLine($job['qty'])],
        User::findOrFail($job['user']),
    );

    echo json_encode(['ok' => true]);
} catch (StockException $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
