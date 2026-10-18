<?php

namespace App\Jobs;

use App\Imports\ImportEngine;
use App\Models\ImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads an uploaded import file into import_row and checks every row, in the
 * background; the page shows the progress (import_job.processed_rows).
 */
class ReadImportFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $jobId) {}

    public function handle(ImportEngine $engine): void
    {
        $job = ImportJob::find($this->jobId);

        if ($job !== null && $job->status === 'UPLOADED') {
            $engine->read($job);
        }
    }

    public function failed(\Throwable $exception): void
    {
        ImportJob::whereKey($this->jobId)->where('status', 'UPLOADED')
            ->update(['status' => 'FAILED', 'error_message' => __('imports.errors.unreadable')]);
    }
}
