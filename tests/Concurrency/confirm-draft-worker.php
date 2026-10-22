<?php

/*
 * Child process for ConcurrentDraftConfirmTest: boots the app on its own
 * database connection, waits at the start line (a shared advisory lock the
 * test holds exclusively), then tries to confirm the draft. Prints one line of JSON.
 *
 *   php confirm-draft-worker.php '{"barrier":123,"draft":"...","user":"..."}'
 */

use App\Models\StockDraft;
use App\Models\User;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\StockException;
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
    $documents = app(DraftService::class)->confirm(
        StockDraft::withoutGlobalScopes()->findOrFail($job['draft']),
        User::findOrFail($job['user']),
    );

    echo json_encode(['ok' => true, 'documents' => count($documents)]);
} catch (StockException $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
