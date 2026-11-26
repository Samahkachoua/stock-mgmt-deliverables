<?php

namespace App\Console\Commands;

use App\Support\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * Deletes notifications older than Notifier::KEEP_DAYS (90 days). The full
 * history of every operation stays in audit_log.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete notifications older than '.Notifier::KEEP_DAYS.' days';

    public function handle(Notifier $notifier): int
    {
        $this->info('Deleted '.$notifier->prune().' old notifications.');

        return self::SUCCESS;
    }
}
