<?php

namespace App\Support\Drafts;

use App\Mail\DraftEmail;
use App\Models\User;
use App\Services\Stock\DraftService;
use App\Support\Notifications\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the people who can confirm a draft receipt / order that one was
 * added, changed or cancelled: an in-app notification (Notifier) and an email
 * for those who keep the kind's email setting on ("Email about orders" /
 * "Email about draft receipts", DraftUi::notifyColumn()).
 *
 * Who: active users with the kind's confirm permission who may see the draft
 * (in its warehouse, or their customer's branch), never the person who did
 * it. When that person may confirm it themselves, nobody is told: it waits
 * for nobody else.
 *
 * Several confirmers all get it; the first to confirm wins (the draft row is
 * locked) and everyone's notification closes (confirmed()). Called inside the
 * save's transaction; emails are queued after commit in each recipient's
 * language. A problem is logged and never stops the draft being saved.
 */
final class DraftNotifier
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * A draft was added (DraftEmail::CREATED) or changed (DraftEmail::UPDATED).
     *
     * @param  DraftEmail::CREATED|DraftEmail::UPDATED  $event
     */
    public function saved(string $draftId, string $event, User $by): void
    {
        $header = $this->header($draftId);
        $recipients = $this->recipients($header, $by);

        $written = $this->notifier->open('draft.'.$event, 'stock_draft', $draftId, $recipients, $by, $this->params($header, $by));

        $this->email($header, $written, $event);
    }

    /**
     * A draft was cancelled: its confirmers are told (with the reason), and
     * every open notification about it closes.
     */
    public function cancelled(string $draftId, User $by, string $reason): void
    {
        $header = $this->header($draftId);
        $recipients = $this->recipients($header, $by);

        $written = $this->notifier->inform('draft.'.DraftEmail::CANCELLED, 'stock_draft', $draftId, $recipients, $by,
            $this->params($header, $by) + ['reason' => $reason]);
        $this->notifier->resolve('stock_draft', $draftId, 'CANCELLED', $by);

        $this->email($header, $written, DraftEmail::CANCELLED);
    }

    /**
     * A draft was confirmed: nobody needs to act on it any more.
     */
    public function confirmed(string $draftId, User $by): void
    {
        $this->notifier->resolve('stock_draft', $draftId, 'CONFIRMED', $by);
    }

    /**
     * Who is told about $header when $by changes it.
     *
     * @return Collection<int, User>
     */
    public function recipients(object $header, User $by): Collection
    {
        $kind = DraftTypes::get($header->transaction_type);

        if ($by->hasPermission($kind->confirmPermission()) && $by->canAccessWarehouse((string) $header->warehouse_id)) {
            return collect();
        }

        return User::query()
            ->where('active', true)
            ->where('id', '<>', $by->id)
            ->whereHas('warehouses', fn ($query) => $query->where('warehouse.id', $header->warehouse_id)->where('warehouse.active', true))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($kind->confirmPermission()) && DraftService::canSee($header, $user))
            ->values();
    }

    private function header(string $draftId): object
    {
        return DB::table('stock_draft')->where('id', $draftId)->first();
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
            'actor' => $by->full_name,
        ];
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function email(object $header, Collection $recipients, string $event): void
    {
        $column = DraftTypes::get($header->transaction_type)->notifyColumn();

        foreach ($recipients as $user) {
            if (! $user->{$column} || blank($user->email)) {
                continue;
            }

            try {
                Mail::to($user->email)
                    ->locale($user->preferred_lang ?: config('app.locale'))
                    ->queue(new DraftEmail((string) $header->id, $event));
            } catch (Throwable $e) {
                Log::error('Draft email not sent', ['draft' => $header->doc_no, 'event' => $event, 'exception' => $e]);
            }
        }
    }
}
