<?php

namespace App\Support\Home;

use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Home's "Pick up where you left off": the documents and drafts the user last
 * worked on (created, edited, dispatched, approved…), newest first, one row
 * per record with what they did to it last.
 *
 * Read from audit_log, then loaded through the models, so the warehouse and
 * customer scopes apply; each type also needs the permission of the page the
 * row opens. A record the user can no longer open is left out.
 *
 * @phpstan-type Row array{number: string, kind: TypeUi|DraftUi, draft: bool, status: string, customer: string, event: string, at: CarbonImmutable, url: string}
 */
final class RecentActivity
{
    /** Rows shown. */
    public const LIMIT = 8;

    /** The user's latest audit rows looked at (several can be about one record). */
    private const SCAN = 200;

    /** audit_log.action => what the user did last (row key `event`). */
    private const EVENTS = [
        'CREATE' => 'created',
        'UPDATE' => 'edited',
        'SUBMIT' => 'sent_for_approval',
        'APPROVE' => 'approved',
        'REJECT' => 'rejected',
        'POST' => 'posted',
        'CONFIRM' => 'confirmed',
        'DISPATCH' => 'dispatched',
        'RECEIVE' => 'received',
        'DELIVER' => 'delivered',
        'CANCEL' => 'cancelled',
    ];

    /**
     * @return list<Row>
     */
    public static function forUser(User $user): array
    {
        // Newest first: the first row met for a record is the user's last action on it.
        $latest = [];
        DB::table('audit_log')
            ->where('user_id', $user->id)
            ->whereIn('entity', ['stock_transaction', 'stock_draft'])
            ->orderByDesc('id')
            ->limit(self::SCAN)
            ->get(['entity', 'entity_id', 'action', 'created_at'])
            ->each(function (object $entry) use (&$latest) {
                $latest[$entry->entity.':'.$entry->entity_id] ??= $entry;
            });

        if ($latest === []) {
            return [];
        }

        $ids = fn (string $entity) => collect($latest)->where('entity', $entity)->pluck('entity_id')->map(fn ($id) => (string) $id)->values()->all();
        $records = self::transactions($user, $ids('stock_transaction')) + self::drafts($user, $ids('stock_draft'));

        $rows = [];
        foreach ($latest as $key => $entry) {
            if (! isset($records[$key])) {
                continue;
            }

            $rows[] = $records[$key] + [
                'event' => self::EVENTS[$entry->action] ?? 'edited',
                'at' => CarbonImmutable::parse($entry->created_at),
            ];

            if (count($rows) === self::LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> "stock_transaction:<id>" => row without event / at
     */
    private static function transactions(User $user, array $ids): array
    {
        $types = array_filter(TransactionTypes::all(), fn (TypeUi $type) => Gate::forUser($user)->allows($type->viewGate()));

        if ($ids === [] || $types === []) {
            return [];
        }

        return StockTransaction::query()
            ->join('customer', 'customer.id', '=', 'stock_transaction.customer_id')
            ->whereIn('stock_transaction.id', $ids)
            ->whereIn('stock_transaction.transaction_type', array_keys($types))
            ->get(['stock_transaction.id', 'stock_transaction.doc_no', 'stock_transaction.transaction_type', 'stock_transaction.status', 'customer.name as customer_name'])
            ->mapWithKeys(fn (StockTransaction $row) => ['stock_transaction:'.$row->id => [
                'number' => $row->doc_no,
                'kind' => $types[$row->transaction_type],
                'draft' => false,
                'status' => $row->status,
                'customer' => (string) $row->getAttribute('customer_name'),
                'url' => $types[$row->transaction_type]->route('show', $row->doc_no),
            ]])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> "stock_draft:<id>" => row without event / at
     */
    private static function drafts(User $user, array $ids): array
    {
        $drafts = array_filter(DraftTypes::all(), fn (DraftUi $draft) => Gate::forUser($user)->allows('drafts.'.strtolower($draft->code).'.open'));

        if ($ids === [] || $drafts === []) {
            return [];
        }

        return StockDraft::query()
            ->join('customer', 'customer.id', '=', 'stock_draft.customer_id')
            ->whereIn('stock_draft.id', $ids)
            ->whereIn('stock_draft.transaction_type', array_keys($drafts))
            ->get(['stock_draft.id', 'stock_draft.doc_no', 'stock_draft.transaction_type', 'stock_draft.status', 'customer.name as customer_name'])
            ->mapWithKeys(fn (StockDraft $row) => ['stock_draft:'.$row->id => [
                'number' => $row->doc_no,
                'kind' => $drafts[$row->transaction_type],
                'draft' => true,
                'status' => $row->status,
                'customer' => (string) $row->getAttribute('customer_name'),
                'url' => $drafts[$row->transaction_type]->route('show', $row->doc_no),
            ]])
            ->all();
    }
}
