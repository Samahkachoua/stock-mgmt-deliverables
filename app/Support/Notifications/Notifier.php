<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes in-app notifications (table `notification`). Called inside the
 * action's DB::transaction(), so a rolled-back action leaves no notification;
 * each write runs in its own savepoint, so a notification problem is logged
 * and never stops the action itself.
 *
 *   open()    "something waits for you" (ACTION): one open row per person and
 *             record; a later event (an edit) updates it and makes it unread again.
 *   resolve() someone approved / rejected / confirmed / cancelled the record:
 *             every open ACTION row of it closes and leaves the unread count.
 *   inform()  "here is what happened to yours" (INFO): read or unread only. A
 *             recipient's open ACTION row of the record becomes this INFO row,
 *             so one record keeps one row per person.
 *
 * Emails are sent by the callers (each has its own email and setting); the
 * returned users are the ones a row was written for.
 */
final class Notifier
{
    public const ACTION = 'ACTION';

    public const INFO = 'INFO';

    /** Days a notification is kept (notifications:prune); audit_log keeps the full history. */
    public const KEEP_DAYS = 90;

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, string|null>  $params
     * @return Collection<int, User> the recipients written
     */
    public function open(string $event, string $entity, string $entityId, Collection $recipients, User $actor, array $params): Collection
    {
        return $this->safely(fn () => $recipients->filter(function (User $user) use ($event, $entity, $entityId, $actor, $params) {
            $changes = [
                'event' => $event,
                'actor_id' => $actor->id,
                'params' => json_encode($params),
                'read_at' => null,
                'updated_at' => now(),
            ];

            $updated = $this->openRow($user->id, $entity, $entityId)->update($changes);

            if ($updated === 0) {
                DB::table('notification')->insert($changes + [
                    'user_id' => $user->id,
                    'kind' => self::ACTION,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'created_at' => now(),
                ]);
            }

            return true;
        })->values(), collect());
    }

    /**
     * @param  'APPROVED'|'REJECTED'|'CONFIRMED'|'CANCELLED'  $outcome
     */
    public function resolve(string $entity, string $entityId, string $outcome, User $by): void
    {
        $this->safely(fn () => DB::table('notification')
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('kind', self::ACTION)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by' => $by->id, 'outcome' => $outcome, 'updated_at' => now()]), 0);
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<string, string|null>  $params
     * @return Collection<int, User> the recipients written
     */
    public function inform(string $event, string $entity, string $entityId, Collection $recipients, User $actor, array $params): Collection
    {
        return $this->safely(fn () => $recipients->filter(function (User $user) use ($event, $entity, $entityId, $actor, $params) {
            $changes = [
                'kind' => self::INFO,
                'event' => $event,
                'actor_id' => $actor->id,
                'params' => json_encode($params),
                'read_at' => null,
                'updated_at' => now(),
            ];

            if ($this->openRow($user->id, $entity, $entityId)->update($changes) === 0) {
                DB::table('notification')->insert($changes + [
                    'user_id' => $user->id,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'created_at' => now(),
                ]);
            }

            return true;
        })->values(), collect());
    }

    /**
     * Deletes notifications older than KEEP_DAYS.
     */
    public function prune(): int
    {
        return DB::table('notification')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    private function openRow(string $userId, string $entity, string $entityId): Builder
    {
        return DB::table('notification')
            ->where('user_id', $userId)
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('kind', self::ACTION)
            ->whereNull('resolved_at');
    }

    /**
     * Runs $write in a savepoint: a failure is logged and rolled back alone.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $write, mixed $fallback): mixed
    {
        try {
            return DB::transaction($write);
        } catch (Throwable $e) {
            Log::error('Notification not saved', ['exception' => $e]);

            return $fallback;
        }
    }
}
