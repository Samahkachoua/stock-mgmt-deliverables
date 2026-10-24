<?php

namespace App\Support\Drafts;

use App\Mail\DraftEmail;
use App\Models\StockDraft;
use App\Models\User;
use App\Services\Stock\DraftService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a draft receipt or order that was added, changed or cancelled to the
 * people who can confirm it: active users with the kind's confirm permission
 * who may see it (in its warehouse, or their customer's branch) and keep the
 * kind's email setting on ("Email about orders" / "Email about draft
 * receipts", DraftUi::notifyColumn()). The person who did it gets it too when
 * they qualify.
 *
 * Each email is queued in the recipient's language. A mail problem is logged
 * and never stops the draft from being saved or cancelled.
 */
final class DraftNotifier
{
    /**
     * @param  DraftEmail::CREATED|DraftEmail::UPDATED|DraftEmail::CANCELLED  $event
     */
    public function notify(StockDraft $draft, string $event): void
    {
        try {
            foreach ($this->recipients($draft, DraftTypes::get($draft->transaction_type)) as $user) {
                Mail::to($user->email)
                    ->locale($user->preferred_lang ?: config('app.locale'))
                    ->queue(new DraftEmail($draft->id, $event));
            }
        } catch (Throwable $e) {
            Log::error('Draft email not sent', ['draft' => $draft->doc_no, 'event' => $event, 'exception' => $e]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function recipients(StockDraft $draft, DraftUi $kind): Collection
    {
        $header = DB::table('stock_draft')->where('id', $draft->id)->first();

        return User::query()
            ->where('active', true)
            ->where($kind->notifyColumn(), true)
            ->where('email', '<>', '')
            ->whereHas('warehouses', fn ($query) => $query->where('warehouse.id', $header->warehouse_id)->where('warehouse.active', true))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($kind->confirmPermission()) && DraftService::canSee($header, $user))
            ->values();
    }
}
