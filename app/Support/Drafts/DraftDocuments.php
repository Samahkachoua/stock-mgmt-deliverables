<?php

namespace App\Support\Drafts;

use App\Models\Product;
use App\Models\StockDraft;
use App\Services\Stock\UnitConverter;
use App\Support\CompanyTime;
use App\Support\DateRange;
use App\Support\Format;
use App\Support\StorageStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Drafts for the list and details pages. Reads go through StockDraft, so the
 * customer scope and DraftScope (warehouse / branches) always apply.
 *
 * @phpstan-type DraftRow array{id: string, reference: string, date: CarbonImmutable, customer: string, branch: string, warehouse: string, status: string, created_by: string, items: int, total: float}
 */
final class DraftDocuments
{
    /**
     * One page of a kind's drafts, newest first; with $draftsFirst, those still
     * to confirm come first.
     *
     * @param  array{search?: string, customer?: string, branch?: string, status?: string, dates?: DateRange, drafts_first?: bool}  $filters
     * @return LengthAwarePaginator<int, DraftRow>
     */
    public static function page(DraftUi $kind, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return StockDraft::query()
            ->join('customer', 'customer.id', '=', 'stock_draft.customer_id')
            ->join('customer_branch as branch', 'branch.id', '=', 'stock_draft.customer_branch_id')
            ->join('warehouse', 'warehouse.id', '=', 'stock_draft.warehouse_id')
            ->join('app_user', 'app_user.id', '=', 'stock_draft.created_by')
            ->where('stock_draft.transaction_type', $kind->code)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(stock_draft.doc_no) like ?', [$like])
                ->orWhereRaw('lower(customer.name) like ?', [$like])
                ->orWhereRaw('lower(branch.name) like ?', [$like])))
            ->when($filters['customer'] ?? '', fn ($query, string $customer) => $query->where('stock_draft.customer_id', $customer))
            ->when($filters['branch'] ?? '', fn ($query, string $branch) => $query->where('stock_draft.customer_branch_id', $branch))
            ->when($filters['status'] ?? '', fn ($query, string $status) => $query->where('stock_draft.status', $status))
            ->when($filters['dates'] ?? null, fn ($query, DateRange $dates) => $dates->whereDay($query, 'stock_draft.document_date'))
            ->select([
                'stock_draft.*',
                'customer.name as customer_name',
                'branch.name as branch_name',
                'warehouse.name as warehouse_name',
                'app_user.full_name as creator_name',
            ])
            ->selectSub(DB::table('stock_draft_line')->selectRaw('count(*)')->whereColumn('draft_id', 'stock_draft.id'), 'line_count')
            ->selectSub(DB::table('stock_draft_line')->selectRaw('coalesce(sum(qty_base), 0)')->whereColumn('draft_id', 'stock_draft.id'), 'total_base')
            ->when($filters['drafts_first'] ?? false, fn ($query) => $query->orderByRaw("(stock_draft.status = 'DRAFT') DESC"))
            ->orderByDesc('stock_draft.document_date')
            ->orderByDesc('stock_draft.created_at')
            ->paginate($perPage, page: $page)
            ->through(fn (StockDraft $row) => [
                'id' => $row->id,
                'reference' => $row->doc_no,
                'date' => CarbonImmutable::parse($row->getAttribute('document_date')),
                'customer' => (string) $row->getAttribute('customer_name'),
                'branch' => (string) $row->getAttribute('branch_name'),
                'warehouse' => (string) $row->getAttribute('warehouse_name'),
                'status' => $row->status,
                'created_by' => (string) $row->getAttribute('creator_name'),
                'items' => (int) $row->getAttribute('line_count'),
                'total' => (float) $row->getAttribute('total_base'),
            ]);
    }

    /**
     * A draft the user may see, with its lines, the documents confirming it
     * created and its activity; null if there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function find(DraftUi $kind, string $reference): ?array
    {
        $draft = StockDraft::query()->where('transaction_type', $kind->code)->where('doc_no', $reference)->first();

        if ($draft === null) {
            return null;
        }

        $timezone = CompanyTime::zone();
        $header = DB::table('stock_draft as d')
            ->join('customer as c', 'c.id', '=', 'd.customer_id')
            ->join('customer_branch as b', 'b.id', '=', 'd.customer_branch_id')
            ->join('warehouse as w', 'w.id', '=', 'd.warehouse_id')
            ->join('app_user as u', 'u.id', '=', 'd.created_by')
            ->leftJoin('app_user as cu', 'cu.id', '=', 'd.confirmed_by')
            ->where('d.id', $draft->id)
            ->first(['d.*', 'c.name as customer_name', 'b.name as branch_name', 'b.code as branch_code', 'w.name as warehouse_name', 'u.full_name as creator_name', 'cu.full_name as confirmer_name']);

        $lines = self::lines($draft->id);

        return [
            'model' => $draft,
            'id' => $header->id,
            'reference' => $header->doc_no,
            'type' => $header->transaction_type,
            'status' => $header->status,
            'version' => $header->updated_at,
            'date' => CarbonImmutable::parse($header->document_date),
            'customer_id' => $header->customer_id,
            'customer' => $header->customer_name,
            'branch_id' => $header->customer_branch_id,
            'branch' => $header->branch_name,
            'warehouse_id' => $header->warehouse_id,
            'warehouse' => $header->warehouse_name,
            'note' => $header->reference_note,
            'created_by_id' => $header->created_by,
            'created_by' => $header->creator_name,
            'created_at' => CarbonImmutable::parse($header->created_at)->setTimezone($timezone),
            'confirmed_by' => $header->confirmer_name,
            'confirmed_at' => $header->confirmed_at ? CarbonImmutable::parse($header->confirmed_at)->setTimezone($timezone) : null,
            'cancel_reason' => $header->cancel_reason,
            'lines' => $lines,
            'items' => count($lines),
            'total' => array_sum(array_column($lines, 'base_qty')),
            'documents' => self::documents($header->id),
            'activity' => self::activity($header->id, $timezone),
        ];
    }

    /**
     * The header facts of a draft, as the details page and its print view show them
     * (the print leaves out created by / on).
     *
     * @param  array<string, mixed>  $draft  from find()
     * @return list<array{label: string, value: mixed}>
     */
    public static function summary(DraftUi $kind, array $draft, bool $print = false): array
    {
        return [
            ['label' => __('transactions.details.customer'), 'value' => $draft['customer']],
            ['label' => $kind->label('branch'), 'value' => $draft['branch']],
            ['label' => __('transactions.details.date'), 'value' => Format::date($draft['date'])],
            ['label' => __('drafts.details.warehouse'), 'value' => $draft['warehouse']],
            ['label' => __('transactions.details.reference_note'), 'value' => $draft['note']],
            ...($print ? [] : [
                ['label' => __('transactions.details.created_by'), 'value' => $draft['created_by']],
                ['label' => __('transactions.details.created_on'), 'value' => Format::dateTime($draft['created_at'])],
            ]),
            ...($draft['confirmed_by'] ? [['label' => __('drafts.details.confirmed_by'), 'value' => $draft['confirmed_by']]] : []),
            ...($draft['confirmed_at'] ? [['label' => __('drafts.details.confirmed_on'), 'value' => Format::dateTime($draft['confirmed_at'])]] : []),
            ...($draft['cancel_reason'] ? [['label' => __('drafts.details.cancel_reason'), 'value' => $draft['cancel_reason']]] : []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(string $draftId): array
    {
        $rows = DB::table('stock_draft_line as l')
            ->join('unit as u', 'u.id', '=', 'l.unit_id')
            ->where('l.draft_id', $draftId)
            ->orderBy('l.line_no')
            ->get(['l.*', 'u.code as unit']);

        $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($rows->pluck('product_id')->unique())->keyBy('id');

        return $rows->map(function ($row) use ($products) {
            $product = $products[$row->product_id];
            $converter = UnitConverter::forProduct($product);
            $serials = json_decode((string) $row->serials, true) ?: [];

            return [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'code' => $product->sku,
                'name' => $product->name(),
                'storage' => StorageStatuses::label($product->storage_status),
                'unit' => $row->unit,
                'qty' => (float) $row->qty_entered,
                'base_qty' => (float) $row->qty_base,
                'base_unit' => $converter->base(),
                'expiry' => $row->expiry_date,
                'serials' => $serials,
                'details' => $serials === [] ? [] : [__('transactions.details.serials', ['serials' => implode(', ', $serials)])],
            ];
        })->values()->all();
    }

    /**
     * The receipt / delivery note confirming the draft created (drafts confirmed
     * before a draft had one warehouse may have created one per warehouse).
     *
     * @return list<array{reference: string, type: string, warehouse: string, status: string}>
     */
    private static function documents(string $draftId): array
    {
        return DB::table('stock_transaction as t')
            ->join('warehouse as w', 'w.id', '=', 't.warehouse_id')
            ->where('t.draft_id', $draftId)
            ->orderBy('t.id')
            ->get(['t.doc_no', 't.transaction_type', 't.status', 'w.name'])
            ->map(fn ($row) => ['reference' => $row->doc_no, 'type' => $row->transaction_type, 'warehouse' => $row->name, 'status' => $row->status])
            ->all();
    }

    /**
     * @return list<array{event: string, by: string, at: CarbonImmutable, state: string, reason: string|null}>
     */
    private static function activity(string $draftId, string $timezone): array
    {
        $events = [
            'CREATE' => ['created', 'done'],
            'UPDATE' => ['edited', 'done'],
            'CONFIRM' => ['confirmed', 'success'],
            'CANCEL' => ['cancelled', 'danger'],
        ];

        return DB::table('audit_log as a')
            ->leftJoin('app_user as u', 'u.id', '=', 'a.user_id')
            ->where('a.entity', 'stock_draft')
            ->where('a.entity_id', $draftId)
            ->orderBy('a.id')
            ->get(['a.action', 'a.created_at', 'a.after_data', 'u.full_name'])
            ->map(fn ($entry) => [
                'event' => $events[$entry->action][0] ?? 'edited',
                'by' => (string) $entry->full_name,
                'at' => CarbonImmutable::parse($entry->created_at)->setTimezone($timezone),
                'state' => $events[$entry->action][1] ?? 'done',
                'reason' => json_decode((string) $entry->after_data, true)['reason'] ?? null,
            ])
            ->values()
            ->all();
    }
}
