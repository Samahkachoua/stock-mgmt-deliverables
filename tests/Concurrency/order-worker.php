<?php

/*
 * Child process for ConcurrentOrderTest: boots the app on its own database
 * connection, waits at the start line (a shared advisory lock the test holds
 * exclusively), then tries to save an order (which reserves its stock).
 * Prints one line of JSON.
 *
 *   php order-worker.php '{"barrier":123,"warehouse":"...","customer":"...","branch":"...","product":"...","user":"...","qty":10}'
 */

use App\Models\User;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\StockException;
use Carbon\CarbonImmutable;
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
    $draft = app(DraftService::class)->save(
        new DraftInput('OUT', $job['customer'], $job['branch'], $job['warehouse'], CarbonImmutable::today(), null, [
            'r1' => new DraftLineInput($job['product'], 'PC', (string) $job['qty']),
        ], deliveryDate: CarbonImmutable::today(), priority: 'NORMAL'),
        User::findOrFail($job['user']),
    );

    echo json_encode(['ok' => true, 'doc_no' => $draft->doc_no]);
} catch (StockException $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
