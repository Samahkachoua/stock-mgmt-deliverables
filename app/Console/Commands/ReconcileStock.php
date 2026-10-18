<?php

namespace App\Console\Commands;

use App\Mail\StockMismatchFound;
use App\Models\User;
use App\Services\Stock\Reconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly (routes/console.php): every stock balance must equal the sum of its
 * movements. On any difference: one critical log entry per key, an email to
 * the administrators, and exit code 1 so monitoring notices. Fixes nothing.
 */
class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile';

    protected $description = 'Check that every stock balance equals the sum of its movements; log and email the administrators on any mismatch.';

    public function handle(Reconciliation $reconciliation): int
    {
        $mismatches = $reconciliation->mismatches();

        if ($mismatches === []) {
            Log::info('stock:reconcile: every balance matches its movements.');
            $this->info('Every balance matches its movements.');

            return self::SUCCESS;
        }

        foreach ($mismatches as $mismatch) {
            Log::critical('stock:reconcile: balance does not match movements', $mismatch);
        }

        $recipients = $this->recipients();

        foreach ($recipients as $admin) {
            Mail::to($admin->email)->locale($admin->preferred_lang ?: config('app.locale'))->send(new StockMismatchFound($mismatches));
        }

        if ($recipients->isEmpty()) {
            Log::critical('stock:reconcile: no administrator to email (set RECONCILE_MAIL_TO or an active ADMIN user).');
        }

        $this->error(count($mismatches).' balance(s) do not match their movements. Administrators emailed: '.$recipients->count().'.');

        return self::FAILURE;
    }

    /**
     * RECONCILE_MAIL_TO (comma-separated addresses) when set; otherwise every
     * active user with the ADMIN role.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $configured = array_filter(array_map('trim', explode(',', (string) config('stock.reconcile_mail_to'))));

        if ($configured !== []) {
            return collect($configured)->map(fn (string $email) => User::whereRaw('lower(email) = lower(?)', [$email])->first()
                ?? (new User)->forceFill(['email' => $email, 'preferred_lang' => config('app.locale')]))->values();
        }

        return User::query()->where('active', true)
            ->whereHas('roles', fn ($query) => $query->where('code', 'ADMIN'))
            ->get();
    }
}
