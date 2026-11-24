<?php

namespace App\Reports;

use App\Models\StockDraft;
use App\Models\User;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every line of every draft of one kind (Order, Draft receipt), in any status,
 * by draft date, with the operations confirming it created. Each kind adds its
 * own columns (extraColumns / extraCells).
 */
abstract class DraftLinesReport extends Report
{
    /** stock_draft.transaction_type: what confirming creates (IN / OUT). */
    abstract protected function type(): string;

    /** Header of the "created when confirmed" column (Delivery notes / Receipts). */
    abstract protected function createdLabel(): string;

    /** The report shows the quantity in the base unit next to the one entered. */
    protected function showsBaseQuantity(): bool
    {
        return true;
    }

    /**
     * Columns after the branch, before the item.
     *
     * @return array<string, array{label: string, align?: string, priority?: int, card?: string}>
     */
    protected function extraColumns(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraCells(object $row): array
    {
        return [];
    }

    public function filters(): array
    {
        $statuses = ['' => 'transactions.filters.all_statuses'];

        foreach (config('transactions.draft_statuses') as $code => $status) {
            $statuses[$code] = 'drafts.status.'.$status['label'];
        }

        return [
            Filter::search('reports.filters.search_item'),
            ...Filter::dates(),
            Filter::select('status', 'reports.filters.status', $statuses),
            Filter::warehouse(),
            Filter::customer(),
            Filter::branch(),
        ];
    }

    public function columns(): array
    {
        return [
            'date' => ['label' => 'reports.columns.date'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'status' => ['label' => 'reports.columns.status', 'card' => 'badge'],
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'branch' => ['label' => 'reports.columns.branch', 'priority' => 2],
            ...$this->extraColumns(),
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            'batch' => ['label' => 'reports.columns.batch', 'priority' => 2],
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'qty' => ['label' => 'reports.columns.qty_entered', 'align' => 'end'],
            ...($this->showsBaseQuantity() ? ['qty_base' => ['label' => 'reports.columns.qty_base', 'align' => 'end', 'priority' => 2]] : []),
            'created' => ['label' => $this->createdLabel(), 'priority' => 2],
            'created_by' => ['label' => 'reports.columns.created_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    /**
     * @return Builder<StockDraft>
     */
    protected function query(array $filters, User $user): Builder
    {
        return StockDraft::query()
            ->join('stock_draft_line as l', 'l.draft_id', '=', 'stock_draft.id')
            ->join('unit as un', 'un.id', '=', 'l.unit_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_draft.warehouse_id')
            ->join('customer as c', 'c.id', '=', 'stock_draft.customer_id')
            ->join('customer_branch as b', 'b.id', '=', 'stock_draft.customer_branch_id')
            ->join('app_user as u', 'u.id', '=', 'stock_draft.created_by')
            ->where('stock_draft.transaction_type', $this->type())
            ->whereIn('stock_draft.warehouse_id', $this->warehouseIds($filters, $user))
            ->tap(fn ($query) => $this->dates($filters)->whereDay($query, 'stock_draft.document_date'))
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'l.product_id'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('stock_draft.status', $filters['status']))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_draft.customer_id', $filters['customer']))
            ->when($filters['branch'] !== '', fn ($query) => $query->where('stock_draft.customer_branch_id', $filters['branch']))
            ->select([
                'stock_draft.id', 'stock_draft.doc_no', 'stock_draft.document_date', 'stock_draft.status',
                'stock_draft.delivery_date', 'stock_draft.priority',
                'l.product_id', 'l.qty_entered', 'l.qty_base', 'l.expiry_date', 'l.batch_no', 'un.code as unit_code',
                'w.name as warehouse_name', 'c.name as customer_name', 'b.name as branch_name', 'u.full_name as created_by_name',
                // The operations confirming it created (one; more only if cancelled and confirmed again).
                DB::raw("(SELECT string_agg(t.doc_no, ', ' ORDER BY t.id) FROM stock_transaction t WHERE t.draft_id = stock_draft.id) AS created_refs"),
            ])
            ->orderByDesc('stock_draft.document_date')
            ->orderByDesc('stock_draft.doc_no')
            ->orderBy('l.line_no');
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);

        return [
            'date' => Format::date(CarbonImmutable::parse($row->document_date)),
            'reference' => $this->referenceCell($row->doc_no),
            'status' => ['text' => DraftTypes::statusLabel($row->status), 'tone' => config('transactions.draft_statuses.'.$row->status.'.token') ?? 'cancelled'],
            'warehouse' => (string) $row->warehouse_name,
            'customer' => (string) $row->customer_name,
            'branch' => (string) $row->branch_name,
            ...$this->extraCells($row),
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'batch' => ['text' => (string) $row->batch_no, 'ltr' => true],
            'expiry_date' => $this->expiryDateCell($row->expiry_date),
            'qty' => ['text' => Format::qtyUnit($row->qty_entered, $row->unit_code), 'value' => (float) $row->qty_entered, 'nowrap' => true],
            ...($this->showsBaseQuantity() ? ['qty_base' => $this->qtyCell($row->qty_base, $row->product_id)] : []),
            'created' => ['text' => (string) $row->created_refs, 'ltr' => true],
            'created_by' => (string) $row->created_by_name,
        ];
    }

    public function summary(array $filters, User $user): array
    {
        $drafts = $this->query($filters, $user)->reorder()->distinct()->count('stock_draft.id');

        return [['label' => __('reports.operation_lines.operations'), 'value' => Format::qty($drafts)]];
    }

    public function link(object $row): ?string
    {
        return DraftTypes::get($this->type())->route('show', $row->doc_no);
    }
}
