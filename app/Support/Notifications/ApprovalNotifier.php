<?php

namespace App\Support\Notifications;

use App\Mail\NotificationEmail;
use App\Models\User;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifications about Adjustments / Damage records over the approval limit:
 *
 *   requested()  sent for approval → every active user with the type's
 *                approve permission in its warehouse, except the requester.
 *                (A requester who may approve it never sends one: their
 *                document is self-approved and posted at once, rule 6.)
 *   decided()    approved / rejected → every approver's notification closes,
 *                and the requester is told (with the reason when rejected).
 *
 * Several approvers all get it; the first decision wins (PostingService locks
 * the document) and the others' notifications close. Called inside the
 * action's transaction; emails ("Email about approval requests" /
 * "Email about my approval results") are queued after commit.
 */
final class ApprovalNotifier
{
    public function __construct(private readonly Notifier $notifier) {}

    public function requested(string $transactionId, User $by): void
    {
        $header = DB::table('stock_transaction')->where('id', $transactionId)->first();
        $approve = TransactionTypes::get($header->transaction_type)->approvePermission();

        if ($approve === null) {
            return;
        }

        $approvers = User::query()
            ->where('active', true)
            ->whereNotIn('id', [$by->id, $header->created_by])
            ->whereHas('warehouses', fn ($query) => $query->where('warehouse.id', $header->warehouse_id)->where('warehouse.active', true))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($approve))
            ->values();

        $written = $this->notifier->open('approval.requested', 'stock_transaction', $transactionId, $approvers, $by, $this->params($header, $by));

        $this->email($written->filter(fn (User $user) => $user->notify_approvals), 'approval.requested', $transactionId);
    }

    /**
     * @param  'APPROVED'|'REJECTED'  $decision
     */
    public function decided(string $transactionId, User $by, string $decision, ?string $reason = null): void
    {
        $this->notifier->resolve('stock_transaction', $transactionId, $decision, $by);

        $header = DB::table('stock_transaction')->where('id', $transactionId)->first();
        $requester = User::query()->where('id', $header->created_by)->where('active', true)->first();

        if ($requester === null || $requester->id === $by->id || ! $requester->canAccessWarehouse((string) $header->warehouse_id)) {
            return;
        }

        $event = $decision === 'APPROVED' ? 'approval.approved' : 'approval.rejected';
        $written = $this->notifier->inform($event, 'stock_transaction', $transactionId, collect([$requester]), $by,
            $this->params($header, $by) + ['reason' => $reason]);

        $this->email($written->filter(fn (User $user) => $user->notify_approval_results), $event, $transactionId);
    }

    /**
     * @return array<string, string|null>
     */
    private function params(object $header, User $by): array
    {
        return [
            'reference' => $header->doc_no,
            'type' => $header->transaction_type,
            'customer' => (string) DB::table('customer')->where('id', $header->customer_id)->value('name'),
            'warehouse' => (string) DB::table('warehouse')->where('id', $header->warehouse_id)->value('name'),
            'actor' => $by->full_name,
        ];
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function email(Collection $recipients, string $event, string $transactionId): void
    {
        foreach ($recipients as $user) {
            if (blank($user->email)) {
                continue;
            }

            $notificationId = DB::table('notification')
                ->where('user_id', $user->id)
                ->where('entity', 'stock_transaction')
                ->where('entity_id', $transactionId)
                ->where('event', $event)
                ->orderByDesc('id')
                ->value('id');

            if ($notificationId === null) {
                continue;
            }

            try {
                Mail::to($user->email)
                    ->locale($user->preferred_lang ?: config('app.locale'))
                    ->queue(new NotificationEmail((string) $notificationId));
            } catch (Throwable $e) {
                Log::error('Notification email not sent', ['notification' => $notificationId, 'exception' => $e]);
            }
        }
    }
}
