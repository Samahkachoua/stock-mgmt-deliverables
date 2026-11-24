<?php

namespace App\Reports;

use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Format;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every line of every operation of one type (Receipt, Delivery note, Opening
 * balance), in any status, by document date: "what was on our operations,
 * item by item?". Each type adds its own columns (extraColumns / extraCells).
 */
abstract class OperationLinesReport extends Report
{
    /** transaction_type.code */
    abstract protected function type(): string;

    /** The type's operations carry a customer branch (receipts, delivery notes). */
    protected function hasBranch(): bool
    {
        return true;
    }

    /** The report has a Status column (the Status filter stays either way). */
    protected function showsStatus(): bool
    {
        return true;
    }

    /** The report shows the quantity in the base unit next to the one entered. */
    protected function showsBaseQuantity(): bool
    {
        return true;
    }

    /**
     * Columns after the quantities, before "Created by".
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

    /**
     * Joins and columns the type's extra cells need.
     *
     * @param  Builder<StockTransaction>  $query
     * @return Builder<StockTransaction>
     */
    protected function extend(Builder $query): Builder
    {
        return $query;
    }

    public function filters(): array
    {
        $statuses = ['' => 'transactions.filters.all_statuses'];
        $type = TransactionTypes::get($this->type());

        foreach (array_keys(Statuses::options($type)) as $code) {
            $statuses[$code] = 'transactions.status.'.($type->statusLabel($code) ?? config('transactions.statuses.'.$code.'.label'));
        }

        return [
            Filter::search('reports.filters.search_item'),
            ...Filter::dates(),
            Filter::select('status', 'reports.filters.status', $statuses),
            Filter::warehouse(),
            Filter::customer(),
            ...($this->hasBranch() ? [Filter::branch()] : []),
        ];
    }

    public function columns(): array
    {
        return [
            'date' => ['label' => 'reports.columns.date'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            ...($this->showsStatus() ? ['status' => ['label' => 'reports.columns.status', 'card' => 'badge']] : []),
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            ...($this->hasBranch() ? ['branch' => ['label' => 'reports.columns.branch', 'priority' => 2]] : []),
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            'batch' => ['label' => 'reports.columns.batch', 'priority' => 2],
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'qty' => ['label' => 'reports.columns.qty_entered', 'align' => 'end'],
            ...($this->showsBaseQuantity() ? ['qty_base' => ['label' => 'reports.columns.qty_base', 'align' => 'end', 'priority' => 2]] : []),
            ...$this->extraColumns(),
            'created_by' => ['label' => 'reports.columns.created_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    /**
     * @return Builder<StockTransaction>
     */
    protected function query(array $filters, User $user): Builder
    {
        $query = StockTransaction::query()
            ->join('stock_transaction_line as l', 'l.transaction_id', '=', 'stock_transaction.id')
            ->join('unit as un', 'un.id', '=', 'l.unit_id')
            ->join('warehouse as w', 'w.id', '=', 'stock_transaction.warehouse_id')
            ->leftJoin('customer as c', 'c.id', '=', 'stock_transaction.customer_id')
            ->leftJoin('customer_branch as b', 'b.id', '=', 'stock_transaction.customer_branch_id')
            ->join('app_user as u', 'u.id', '=', 'stock_transaction.created_by')
            ->where('stock_transaction.transaction_type', $this->type())
            ->whereIn('stock_transaction.warehouse_id', $this->warehouseIds($filters, $user))
            ->tap(fn ($query) => $this->dates($filters)->whereDay($query, 'stock_transaction.document_date'))
            ->tap(fn ($query) => $this->whereItem($query, $filters['search'], 'l.product_id'))
            ->when($filters['status'] !== '', fn ($query) => $query->where('stock_transaction.status', $filters['status']))
            ->when($filters['customer'] !== '', fn ($query) => $query->where('stock_transaction.customer_id', $filters['customer']))
            ->when(($filters['branch'] ?? '') !== '', fn ($query) => $query->where('stock_transaction.customer_branch_id', $filters['branch']))
            // A customer user linked to branches: only theirs.
            ->when($this->hasBranch() && $user->branchIds() !== [], fn ($query) => $query->whereIn('stock_transaction.customer_branch_id', $user->branchIds()))
            ->select([
                'stock_transaction.id', 'stock_transaction.doc_no', 'stock_transaction.document_date', 'stock_transaction.status',
                'l.product_id', 'l.qty_entered', 'l.qty_base', 'l.expiry_date', 'l.batch_no', 'un.code as unit_code',
                'w.name as warehouse_name', 'c.name as customer_name', 'b.name as branch_name', 'u.full_name as created_by_name',
            ])
            ->orderByDesc('stock_transaction.document_date')
            ->orderByDesc('stock_transaction.doc_no')
            ->orderBy('l.line_no');

        return $this->extend($query);
    }

    public function row(object $row, array $filters): array
    {
        $product = $this->product($row->product_id);
        $type = TransactionTypes::get($this->type());

        return [
            'date' => Format::date(CarbonImmutable::parse($row->document_date)),
            'reference' => $this->referenceCell($row->doc_no),
            ...($this->showsStatus() ? ['status' => ['text' => Statuses::label($row->status, $type), 'tone' => config('transactions.statuses.'.$row->status.'.token') ?? 'cancelled']] : []),
            'warehouse' => (string) $row->warehouse_name,
            'customer' => (string) $row->customer_name,
            ...($this->hasBranch() ? ['branch' => (string) $row->branch_name] : []),
            'item' => ['text' => $product->name(), 'strong' => true],
            'code' => ['text' => $product->sku, 'ltr' => true],
            'batch' => ['text' => (string) $row->batch_no, 'ltr' => true],
            'expiry_date' => $this->expiryDateCell($row->expiry_date),
            'qty' => ['text' => Format::qtyUnit($row->qty_entered, $row->unit_code), 'value' => (float) $row->qty_entered, 'nowrap' => true],
            ...($this->showsBaseQuantity() ? ['qty_base' => $this->qtyCell($row->qty_base, $row->product_id)] : []),
            ...$this->extraCells($row),
            'created_by' => (string) $row->created_by_name,
        ];
    }

    public function summary(array $filters, User $user): array
    {
        $operations = $this->query($filters, $user)->reorder()->distinct()->count('stock_transaction.id');

        return [['label' => __('reports.operation_lines.operations'), 'value' => Format::qty($operations)]];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink($this->type(), $row->doc_no);
    }
}
