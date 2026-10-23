<?php

/*
 * Child process for ConcurrentDeliveryNoteTest: boots the app on its own database
 * connection, waits at the start line (a shared advisory lock the test holds
 * exclusively), then tries to issue. Prints one line of JSON.
 *
 *   php delivery-note-worker.php '{"barrier":123,"warehouse":"...","customer":"...","product":"...","user":"...","qty":10}'
 */

use App\Models\User;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
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
    $transaction = app(PostingService::class)->post(
        new Document('OUT', $job['warehouse'], $job['customer'], CarbonImmutable::today(), null, [
            'r1' => new DocumentLine($job['product'], 'PC', $job['qty']),
        ]),
        User::findOrFail($job['user']),
    );

    echo json_encode(['ok' => true, 'doc_no' => $transaction->doc_no]);
} catch (StockException $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
