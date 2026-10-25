<?php

namespace App\Support\Transactions;

use App\Models\Product;
use App\Models\StockTransaction;
use App\Services\Stock\UnitConverter;
use App\Support\CompanyTime;
use App\Support\DateRange;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use App\Support\StorageStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Documents for the list and details pages.
 *
 * Reads go through StockTransaction, so the warehouse scope (user_warehouse)
 * and the customer scope (customer portal users) always apply.
 *
 * @phpstan-type ListRow array{id: string, reference: string, date: CarbonImmutable, customer_id: string|null, customer: string, status: string, created_by: string, items: int, total: float|null, reason: string|null, draft: string|null, from: string, to_warehouse: string|null, incoming: bool}
 */
final class Documents
{
    /**
     * One page of a type's documents in one or more warehouses, newest first.
     * Transfers appear in both their source and destination warehouse;
     * `direction` (incoming / outgoing) narrows that down.
     *
     * Approvers see documents waiting for approval first (`pending_first`).
     *
     * @param  list<string>  $warehouseIds  the warehouse filter: one, or all of the user's
     * @param  array{search?: string, customer?: string, status?: string, dates?: DateRange, direction?: string, pending_first?: bool}  $filters
     * @return LengthAwarePaginator<int, ListRow>
     */
    public static function page(TypeUi $type, array $warehouseIds, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $query = StockTransaction::query()
            ->join('customer', 'customer.id', '=', 'stock_transaction.customer_id')
            ->join('app_user', 'app_user.id', '=', 'stock_transaction.created_by')
            ->join('warehouse as from_warehouse', 'from_warehouse.id', '=', 'stock_transaction.warehouse_id')
            ->leftJoin('warehouse as to_warehouse', 'to_warehouse.id', '=', 'stock_transaction.to_warehouse_id')
            ->when($type->hasListColumn('draft'), fn ($query) => $query->leftJoin('stock_draft as draft', 'draft.id', '=', 'stock_transaction.draft_id'))
            ->where('stock_transaction.transaction_type', $type->code)
            ->where(fn ($query) => match ($type->dispatchReceive() ? ($filters['direction'] ?? '') : 'outgoing') {
                'incoming' => $query->whereIn('stock_transaction.to_warehouse_id', $warehouseIds),
                'outgoing' => $query->whereIn('stock_transaction.warehouse_id', $warehouseIds),
                default => $query->whereIn('stock_transaction.warehouse_id', $warehouseIds)->orWhereIn('stock_transaction.to_warehouse_id', $warehouseIds),
            })
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(stock_transaction.doc_no) like ?', [$like])
                ->orWhereRaw('lower(customer.name) like ?', [$like])
                ->when($type->hasListColumn('draft'), fn ($query) => $query->orWhereRaw('lower(draft.doc_no) like ?', [$like]))))
            ->when($filters['customer'] ?? '', fn ($query, string $customer) => $query->where('stock_transaction.customer_id', $customer))
            ->when($filters['status'] ?? '', fn ($query, string $status) => $query->where('stock_transaction.status', $status))
            // The document's own date (the Date column), both days included.
            ->when($filters['dates'] ?? null, fn ($query, DateRange $dates) => $dates->whereDay($query, 'stock_transaction.document_date'))
            ->select([
                'stock_transaction.*',
                'customer.name as customer_name',
                'app_user.full_name as creator_name',
                'from_warehouse.name as from_name',
                'to_warehouse.name as to_name',
            ])
            ->when($type->hasListColumn('draft'), fn ($query) => $query->addSelect('draft.doc_no as draft_reference'))
            ->selectSub(DB::table('stock_transaction_line')->selectRaw('count(*)')->whereColumn('transaction_id', 'stock_transaction.id'), 'line_count')
            ->when($type->hasListColumn('total'), fn ($query) => $query->selectSub(DB::table('stock_transaction_line')->selectRaw('coalesce(sum(qty_base), 0)')->whereColumn('transaction_id', 'stock_transaction.id'), 'total_base'))
            ->when($filters['pending_first'] ?? false, fn ($query) => $query->orderByRaw("(stock_transaction.status = 'PENDING_APPROVAL') DESC"))
            ->orderByDesc('stock_transaction.document_date')
            ->orderByDesc('stock_transaction.created_at');

        return $query->paginate($perPage, page: $page)->through(fn (StockTransaction $row) => [
            'id' => $row->id,
            'reference' => $row->doc_no,
            'date' => CarbonImmutable::parse($row->getAttribute('document_date')),
            'customer_id' => $row->customer_id,
            'customer' => (string) $row->getAttribute('customer_name'),
            'status' => $row->status,
            'created_by' => (string) $row->getAttribute('creator_name'),
            'items' => (int) $row->getAttribute('line_count'),
            // Only for types whose list shows the column.
            'total' => $type->hasListColumn('total') ? (float) $row->getAttribute('total_base') : null,
            'reason' => $type->hasListColumn('reason') ? $row->reason_code : null,
            'draft' => $type->hasListColumn('draft') && $row->getAttribute('draft_reference') !== null ? (string) $row->getAttribute('draft_reference') : null,
            'from' => (string) $row->getAttribute('from_name'),
            // Only transfers have a destination.
            'to_warehouse' => $row->getAttribute('to_name') === null ? null : (string) $row->getAttribute('to_name'),
            // Arriving in the filtered warehouse(s) from elsewhere.
            'incoming' => in_array($row->to_warehouse_id, $warehouseIds, true) && ! in_array($row->warehouse_id, $warehouseIds, true),
        ]);
    }

    /**
     * The header facts of a document (from find()), as the details page and its
     * print view show them. Empty values are skipped by x-transaction-summary.
     * The print leaves out the bookkeeping rows (from draft, created by / on).
     *
     * @param  array<string, mixed>  $document
     * @return list<array{label: string, value: mixed}>
     */
    public static function summary(TypeUi $ui, array $document, bool $print = false): array
    {
        $decision = $document['decision'];

        return [
            ['label' => __('transactions.details.customer'), 'value' => $document['customer']],
            ['label' => __('transactions.details.branch'), 'value' => $document['branch'] ?? null],
            ...($ui->requiresDestination()
                ? [
                    ['label' => __('transactions.details.from_warehouse'), 'value' => $document['warehouse']],
                    ['label' => __('transactions.details.to_warehouse'), 'value' => $document['to_warehouse']],
                ]
                : [['label' => __('transactions.details.warehouse'), 'value' => $document['warehouse']]]),
            ['label' => __('transactions.details.date'), 'value' => Format::date($document['date'])],
            ...($ui->dispatchReceive() && $document['dispatched_at'] ? [['label' => __('transfers.details.dispatched_on'), 'value' => Format::dateTime($document['dispatched_at'])]] : []),
            ...($ui->dispatchReceive() && $document['received_at'] ? [['label' => __('transfers.details.received_on'), 'value' => Format::dateTime($document['received_at'])]] : []),
            ...($ui->hasHeaderField('reason') && $document['reason'] ? [['label' => __('transactions.details.reason'), 'value' => __('transactions.reasons.'.$document['reason'])]] : []),
            ...($decision ? [['label' => __($decision['approved'] ? 'stock.approval.approved_by' : 'stock.approval.rejected_by'), 'value' => $decision['by']]] : []),
            ...($decision['reason'] ?? null ? [['label' => __('stock.approval.rejection_reason'), 'value' => $decision['reason']]] : []),
            ['label' => __('transactions.details.reference_note'), 'value' => $document['note']],
            ...($print ? [] : [
                ['label' => __('transactions.details.from_draft'), 'value' => $document['draft'] ?? null, 'ltr' => true, 'url' => $document['draft_url'] ?? null],
                ['label' => __('transactions.details.created_by'), 'value' => $document['created_by']],
                ['label' => __('transactions.details.created_on'), 'value' => Format::dateTime($document['created_at'])],
            ]),
        ];
    }

    /**
     * A document with its lines and activity, or null if it doesn't exist or the
     * user may not see it.
     *
     * @return array<string, mixed>|null
     */
    public static function find(TypeUi $type, string $reference): ?array
    {
        $transaction = StockTransaction::query()
            ->where('transaction_type', $type->code)
            ->where('doc_no', $reference)
            ->first();

        if ($transaction === null) {
            return null;
        }

        $header = DB::table('stock_transaction as t')
            ->join('customer as c', 'c.id', '=', 't.customer_id')
            ->join('warehouse as w', 'w.id', '=', 't.warehouse_id')
            ->leftJoin('warehouse as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->join('app_user as u', 'u.id', '=', 't.created_by')
            ->leftJoin('customer_branch as b', 'b.id', '=', 't.customer_branch_id')
            ->leftJoin('stock_draft as d', 'd.id', '=', 't.draft_id')
            ->where('t.id', $transaction->id)
            ->first(['t.*', 'c.name as customer_name', 'w.name as warehouse_name', 'tw.name as to_warehouse_name', 'u.full_name as creator_name',
                'b.name as branch_name', 'd.doc_no as draft_reference', 'd.transaction_type as draft_type']);

        $lines = self::lines($transaction->id);

        return [
            'id' => $header->id,
            'reference' => $header->doc_no,
            'date' => CarbonImmutable::parse($header->document_date),
            'customer_id' => $header->customer_id,
            'customer' => $header->customer_name,
            'warehouse_id' => $header->warehouse_id,
            'warehouse' => $header->warehouse_name,
            'status' => $header->status,
            'created_by' => $header->creator_name,
            'created_at' => CarbonImmutable::parse($header->created_at)->setTimezone(CompanyTime::zone()),
            'posted_at' => $header->posted_at ? CarbonImmutable::parse($header->posted_at)->setTimezone(CompanyTime::zone()) : null,
            'note' => $header->reference_note,
            'reason' => $header->reason_code,
            'to_warehouse_id' => $header->to_warehouse_id,
            'to_warehouse' => $header->to_warehouse_name,
            'dispatched_at' => $header->dispatched_at ? CarbonImmutable::parse($header->dispatched_at)->setTimezone(CompanyTime::zone()) : null,
            'received_at' => $header->received_at ? CarbonImmutable::parse($header->received_at)->setTimezone(CompanyTime::zone()) : null,
            // Created by confirming a draft: its branch and the draft itself.
            'branch' => $header->branch_name,
            'draft' => $header->draft_reference,
            'draft_url' => $header->draft_reference === null || ! isset(DraftTypes::all()[$header->draft_type])
                ? null
                : DraftTypes::get($header->draft_type)->route('show', $header->draft_reference),
            'lines' => $lines,
            'items' => count($lines),
            'total' => array_sum(array_column($lines, 'base_qty')),
            'activity' => self::activity($header->id, CompanyTime::zone()),
            'decision' => self::decision($header->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(string $transactionId): array
    {
        $rows = DB::table('stock_transaction_line as l')
            ->join('unit as u', 'u.id', '=', 'l.unit_id')
            ->where('l.transaction_id', $transactionId)
            ->orderBy('l.line_no')
            ->get(['l.id', 'l.product_id', 'l.qty_entered', 'l.qty_base', 'l.qty_received', 'l.shortage_reason', 'l.qty_system', 'l.qty_counted', 'u.code as unit', 'l.expiry_date']);

        $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($rows->pluck('product_id')->unique())->keyBy('id');

        // Which expiry dates each line took stock from (a delivery note line may use several).
        $allocations = DB::table('stock_movement')
            ->where('transaction_id', $transactionId)
            ->whereIn('line_id', $rows->pluck('id'))
            ->where('direction', 'OUT')
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->get(['line_id', 'qty_base', 'expiry_date'])
            ->groupBy('line_id');

        $serials = DB::table('stock_transaction_line_serial as ls')
            ->join('serial_number as s', 's.id', '=', 'ls.serial_id')
            ->whereIn('ls.line_id', $rows->pluck('id'))
            ->orderBy('s.serial_no')
            ->get(['ls.line_id', 's.serial_no'])
            ->groupBy('line_id');

        return $rows->map(function ($row) use ($products, $allocations, $serials) {
            $product = $products[$row->product_id];
            $converter = UnitConverter::forProduct($product);
            $details = [];

            if ($product->is_service) {
                $details[] = __('transactions.form.service_hint');
            }

            if (($taken = $allocations->get($row->id)) && $taken->count() > 0 && $row->expiry_date === null) {
                $details[] = __('transactions.details.from_expiries', [
                    'dates' => $taken->map(fn ($m) => __('transactions.details.expiry_qty', ['date' => Format::date(CarbonImmutable::parse($m->expiry_date)), 'qty' => Format::qtyUnit($m->qty_base, $converter->base())]))->implode(', '),
                ]);
            }

            if ($lineSerials = $serials->get($row->id)) {
                $details[] = __('transactions.details.serials', ['serials' => $lineSerials->pluck('serial_no')->implode(', ')]);
            }

            // Transfers: what arrived, in the unit the line was sent in (null while In transit).
            $received = $row->qty_received === null ? null : $converter->fromBase($row->qty_received, $row->unit);

            if ($row->shortage_reason !== null) {
                $details[] = __('transfers.details.shortage', [
                    'qty' => $converter->breakdown((float) $row->qty_base - (float) $row->qty_received, [$row->unit]),
                    'reason' => __('transfers.reasons.'.$row->shortage_reason),
                ]);
            }

            // Adjustments: what was counted, against what the system had, in the line's unit.
            $counted = $row->qty_counted === null ? null : $converter->fromBase($row->qty_counted, $row->unit);

            return [
                'id' => $row->id,
                'code' => $product->sku,
                'name' => $product->name(),
                'storage' => StorageStatuses::label($product->storage_status),
                'unit' => $row->unit,
                'qty' => $counted ?? (float) $row->qty_entered,
                'base_qty' => (float) $row->qty_base,
                'base_unit' => $converter->base(),
                'expiry' => $row->expiry_date,
                'received' => $received,
                'shortage_reason' => $row->shortage_reason,
                'serials' => $lineSerials?->pluck('serial_no')->all() ?? [],
                'system' => $row->qty_system === null ? null : $converter->fromBase($row->qty_system, $row->unit),
                'details' => $details,
            ];
        })->values()->all();
    }

    /**
     * What happened to the document, from audit_log, oldest first.
     *
     * @return list<array{event: string, by: string, at: CarbonImmutable, state: string}>
     */
    private static function activity(string $transactionId, string $timezone): array
    {
        $events = [
            'CREATE' => ['created', 'done'],
            'POST' => ['posted', 'success'],
            'DISPATCH' => ['dispatched', 'progress'],
            'RECEIVE' => ['received', 'success'],
            'CANCEL' => ['cancelled', 'danger'],
            'SUBMIT' => ['sent_for_approval', 'progress'],
            'APPROVE' => ['approved', 'success'],
            'REJECT' => ['rejected', 'danger'],
        ];

        return DB::table('audit_log as a')
            ->leftJoin('app_user as u', 'u.id', '=', 'a.user_id')
            ->where('a.entity', 'stock_transaction')
            ->where('a.entity_id', $transactionId)
            ->orderBy('a.id')
            ->get(['a.action', 'a.created_at', 'a.after_data', 'u.full_name'])
            ->map(fn ($entry) => [
                'event' => $events[$entry->action][0] ?? 'created',
                'by' => (string) $entry->full_name,
                'at' => CarbonImmutable::parse($entry->created_at)->setTimezone($timezone),
                'state' => $events[$entry->action][1] ?? 'done',
                // Why it was cancelled or rejected.
                'reason' => json_decode((string) $entry->after_data, true)['reason'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Who approved or rejected the document (and why it was rejected), or null
     * while it waits or when it never needed approval.
     *
     * @return array{approved: bool, by: string, reason: string|null}|null
     */
    private static function decision(string $transactionId): ?array
    {
        $approval = DB::table('approval as a')
            ->join('app_user as u', 'u.id', '=', 'a.decided_by')
            ->where('a.transaction_id', $transactionId)
            ->whereNotNull('a.decision')
            ->orderByDesc('a.decided_at')
            ->first(['a.decision', 'a.reason', 'u.full_name']);

        return $approval === null ? null : [
            'approved' => $approval->decision === 'APPROVED',
            'by' => $approval->full_name,
            'reason' => $approval->reason,
        ];
    }
}
