<?php

namespace App\Support\Notifications;

use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * How a notification row reads, in the current language: built when shown
 * (never stored as text), so it follows the reader's language.
 */
final class NotificationView
{
    /** Filter groups of the Notifications page. */
    public const GROUPS = ['approvals', 'orders', 'draft_receipts'];

    /**
     * @return array{id: string, message: string, reason: string|null, outcome: string|null, when: string, unread: bool, resolved: bool, url: string, entity: string, type: string}
     */
    public static function present(object $row): array
    {
        $params = is_array($row->params) ? $row->params : (array) json_decode((string) $row->params, true);

        return [
            'id' => (string) $row->id,
            'message' => __('notifications.events.'.$row->event, [
                'type' => self::typeLabel($row->entity, (string) ($params['type'] ?? '')),
                'reference' => (string) ($params['reference'] ?? ''),
                'actor' => (string) ($params['actor'] ?? ''),
                'customer' => (string) ($params['customer'] ?? ''),
                'warehouse' => (string) ($params['warehouse'] ?? ''),
            ]),
            'reason' => filled($params['reason'] ?? null) ? (string) $params['reason'] : null,
            'outcome' => $row->resolved_at === null ? null : __('notifications.outcomes.'.strtolower((string) $row->outcome), [
                'name' => (string) ($row->resolver_name ?? ''),
                'time' => Format::dateTime(CarbonImmutable::parse($row->resolved_at)),
            ]),
            'when' => Format::dateTime(CarbonImmutable::parse($row->updated_at)),
            'unread' => $row->read_at === null && $row->resolved_at === null,
            'resolved' => $row->resolved_at !== null,
            'url' => route('notifications.open', ['notification' => $row->id]),
            'entity' => (string) $row->entity,
            'type' => (string) ($params['type'] ?? ''),
        ];
    }

    /**
     * The group a notification is filtered by.
     */
    public static function group(string $event, string $type): string
    {
        return match (true) {
            str_starts_with($event, 'approval.') => 'approvals',
            $type === 'IN' => 'draft_receipts',
            default => 'orders',
        };
    }

    /**
     * The record's details page, when $user may still open it; null otherwise.
     */
    public static function target(object $row, User $user): ?string
    {
        $params = (array) json_decode((string) $row->params, true);
        $type = (string) ($params['type'] ?? '');

        try {
            if ($row->entity === 'stock_transaction') {
                $ui = TransactionTypes::get($type);
                $docNo = StockTransaction::query()->whereKey($row->entity_id)->value('doc_no');

                return $docNo !== null && Gate::forUser($user)->allows('documents.'.$ui->slug())
                    ? $ui->route('show', ['reference' => $docNo])
                    : null;
            }

            $kind = DraftTypes::get($type);
            $docNo = StockDraft::query()->whereKey($row->entity_id)->value('doc_no');

            return $docNo !== null && Gate::forUser($user)->allows('drafts.'.strtolower($type).'.open')
                ? $kind->route('show', ['reference' => $docNo])
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rows for $userId, newest first, with the name of whoever closed them.
     */
    public static function query(string $userId): Builder
    {
        return DB::table('notification as n')
            ->leftJoin('app_user as r', 'r.id', '=', 'n.resolved_by')
            ->where('n.user_id', $userId)
            ->select('n.*', 'r.full_name as resolver_name')
            ->orderByDesc('n.updated_at')
            ->orderByDesc('n.id');
    }

    public static function unreadCount(string $userId): int
    {
        return DB::table('notification')->where('user_id', $userId)->whereNull('read_at')->whereNull('resolved_at')->count();
    }

    private static function typeLabel(string $entity, string $type): string
    {
        try {
            return $entity === 'stock_draft' ? DraftTypes::get($type)->label('singular') : TransactionTypes::get($type)->label('singular');
        } catch (Throwable) {
            return '';
        }
    }
}
