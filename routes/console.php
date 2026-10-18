<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. Production runs `php artisan schedule:run` every minute
| (cron or the platform's scheduler; see README "Scheduler").
*/

// Nightly: every stock balance must equal the sum of its movements (SRS FR-8.3).
Schedule::command('stock:reconcile')
    ->dailyAt('02:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Uploaded import files and finished queue jobs are kept; old failed jobs are pruned.
Schedule::command('queue:prune-failed --hours=720')->weekly();
