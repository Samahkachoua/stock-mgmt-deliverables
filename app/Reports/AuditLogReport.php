<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Format;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Audit log (audit.view): who did what, when, with the values before and after.
 * Entries about stock documents are limited to documents the user may see
 * (StockTransaction's warehouse and customer scopes); a customer user sees
 * only those.
 */
final class AuditLogReport extends Report
{
    /** audit_log.entity values with a name in reports.audit_log.entities. */
    public const ENTITIES = ['stock_transaction', 'product', 'app_user', 'role', 'unit', 'warehouse', 'customer', 'import_job'];

    public const ACTIONS = ['CREATE', 'UPDATE', 'DELETE', 'POST', 'SUBMIT', 'APPROVE', 'REJECT', 'DISPATCH', 'RECEIVE', 'CANCEL', 'IMPORT'];

    /** Keys that identify the record in its snapshot, in order of preference. */
    private const IDENTIFIERS = ['doc_no', 'file', 'sku', 'email', 'code', 'name', 'full_name', 'name_en'];

    public function key(): string
    {
        return 'audit-log';
    }

    public function permission(): string
    {
        return 'audit.view';
    }

    public function icon(): string
    {
        return 'history';
    }

    public function filters(): array
    {
        $entities = ['' => 'reports.audit_log.all_records'];
        foreach (self::ENTITIES as $entity) {
            $entities[$entity] = 'reports.audit_log.entities.'.$entity;
        }

        $actions = ['' => 'reports.audit_log.all_actions'];
        foreach (self::ACTIONS as $action) {
            $actions[$action] = 'reports.audit_log.actions.'.$action;
        }

        return [
            Filter::search('reports.audit_log.search'),
            ...Filter::dates(),
            Filter::select('entity', 'reports.audit_log.record', $entities),
            Filter::select('action', 'reports.audit_log.action', $actions),
        ];
    }

    public function columns(): array
    {
        return [
            'when' => ['label' => 'reports.audit_log.when'],
            'who' => ['label' => 'reports.audit_log.who'],
            'action' => ['label' => 'reports.audit_log.action', 'card' => 'badge'],
            'record' => ['label' => 'reports.audit_log.record', 'card' => 'title'],
            'changes' => ['label' => 'reports.audit_log.changes', 'priority' => 2],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        $timezone = $this->today()->getTimezone();
        $search = mb_strtolower($filters['search']);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $visibleDocuments = StockTransaction::query()->select('stock_transaction.id');

        return DB::table('audit_log as a')
            ->leftJoin('app_user as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('stock_transaction as t', fn (JoinClause $join) => $join
                ->on('t.id', '=', 'a.entity_id')
                ->where('a.entity', '=', 'stock_transaction'))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('a.entity', 'stock_transaction')->whereIn('a.entity_id', $visibleDocuments))
                // Master data and users: not for a customer's own login.
                ->when(! $user->isCustomerUser(), fn ($query) => $query->orWhere('a.entity', '<>', 'stock_transaction')))
            ->tap(fn ($query) => $this->dates($filters)->whereInstant($query, 'a.created_at', $timezone))
            ->when($filters['entity'] !== '', fn ($query) => $query->where('a.entity', $filters['entity']))
            ->when($filters['action'] !== '', fn ($query) => $query->where('a.action', $filters['action']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(u.full_name) like ?', [$like])
                ->orWhereRaw('lower(t.doc_no) like ?', [$like])
                ->orWhereRaw('lower(a.before_data::text) like ?', [$like])
                ->orWhereRaw('lower(a.after_data::text) like ?', [$like])))
            ->select(['a.id', 'a.entity', 'a.entity_id', 'a.action', 'a.created_at', 'a.before_data', 'a.after_data', 'u.full_name', 't.doc_no', 't.transaction_type'])
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id');
    }

    public function row(object $row, array $filters): array
    {
        $before = json_decode((string) $row->before_data, true) ?: [];
        $after = json_decode((string) $row->after_data, true) ?: [];
        $entity = Lang::has('reports.audit_log.entities.'.$row->entity) ? __('reports.audit_log.entities.'.$row->entity) : $row->entity;
        $action = Lang::has('reports.audit_log.actions.'.$row->action) ? __('reports.audit_log.actions.'.$row->action) : $row->action;

        return [
            'when' => Format::dateTime(CarbonImmutable::parse($row->created_at)->setTimezone($this->today()->getTimezone())),
            'who' => $row->full_name === null ? __('reports.audit_log.system') : (string) $row->full_name,
            'action' => ['text' => $action, 'strong' => true],
            // One line: a reference split as "DMG-2026-" / "002079" is hard to read.
            'record' => ['text' => $entity.' · '.($row->doc_no ?? $this->identifier($after + $before) ?? $row->entity_id), 'nowrap' => true],
            'changes' => $this->changes($before, $after),
        ];
    }

    public function link(object $row): ?string
    {
        return $row->doc_no === null ? null : $this->documentLink($row->transaction_type, $row->doc_no);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function identifier(array $data): ?string
    {
        foreach (self::IDENTIFIERS as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * "field: before → after", one per line, for the values that changed (all
     * values when the record was created or deleted).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function changes(array $before, array $after): string
    {
        $lines = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = array_key_exists($key, $before) ? $this->value((string) $key, $before[$key]) : null;
            $new = array_key_exists($key, $after) ? $this->value((string) $key, $after[$key]) : null;
            $field = $this->field((string) $key);

            // Unchanged values, and the reference (already in the Record column), are left out.
            if ($old === $new || $key === 'doc_no') {
                continue;
            }

            $lines[] = match (true) {
                $old === null => $field.': '.$new,
                $new === null => $field.': '.$old.' → '.__('reports.audit_log.removed'),
                default => $field.': '.$old.' → '.$new,
            };
        }

        return implode("\n", $lines);
    }

    /**
     * A snapshot field in the reader's language (lang reports.audit_log.fields),
     * or as stored when it has no name there.
     */
    private function field(string $key): string
    {
        return Lang::has('reports.audit_log.fields.'.$key) ? __('reports.audit_log.fields.'.$key) : $key;
    }

    /**
     * A snapshot value as people read it: statuses and document types by their
     * names, yes / no for flags; everything else as stored.
     */
    private function value(string $key, mixed $value): string
    {
        $types = TransactionTypes::all();

        return match (true) {
            $value === null => '∅',
            is_bool($value) => __($value ? 'reports.audit_log.yes' : 'reports.audit_log.no'),
            $key === 'status' && is_string($value) && config('transactions.statuses.'.$value) !== null => Statuses::label($value),
            $key === 'type' && is_string($value) && isset($types[$value]) => __('types.'.$types[$value]->token()),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
