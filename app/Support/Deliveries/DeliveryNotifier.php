<?php

namespace App\Support\Deliveries;

use App\Mail\DeliveryConfirmedEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * When a Delivery note made from an Order is confirmed delivered, emails the
 * manager of the order's branch (Settings › Branches, "Manager email"), if
 * the branch has one. A Delivery note not made from an order, or a branch
 * without a manager email, sends nothing.
 *
 * Queued after the delivery is saved, in the company's default language (the
 * manager has no account, so no language of their own). A mail problem is
 * logged and never stops the delivery from being confirmed.
 */
final class DeliveryNotifier
{
    public function delivered(string $transactionId): void
    {
        try {
            $email = $this->recipient($transactionId);

            if ($email === null) {
                return;
            }

            Mail::to($email)->locale(config('app.locale'))->queue(new DeliveryConfirmedEmail($transactionId));
        } catch (Throwable $e) {
            Log::error('Delivery email not sent', ['transaction' => $transactionId, 'exception' => $e]);
        }
    }

    /**
     * The branch manager's email of a Delivery note made from an order, or null.
     */
    public function recipient(string $transactionId): ?string
    {
        $email = DB::table('stock_transaction as t')
            ->join('customer_branch as b', 'b.id', '=', 't.customer_branch_id')
            ->where('t.id', $transactionId)
            ->whereNotNull('t.draft_id')
            ->value('b.manager_email');

        return filled($email) ? (string) $email : null;
    }
}
