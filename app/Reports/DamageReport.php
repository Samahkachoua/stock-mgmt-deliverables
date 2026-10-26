<?php

namespace App\Reports;

use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Damage: what was written off as damaged, by period, reason and warehouse,
 * with the expiry date and batch of expiry items.
 */
final class DamageReport extends PostedLinesReport
{
    public function key(): string
    {
        return 'damage';
    }

    protected function type(): string
    {
        return 'DAMAGE';
    }

    public function columns(): array
    {
        return [
            'date' => ['label' => 'reports.columns.date'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'warehouse' => ['label' => 'reports.columns.warehouse', 'priority' => 2],
            'customer' => ['label' => 'reports.columns.customer', 'priority' => 2],
            'item' => ['label' => 'reports.columns.item'],
            'code' => ['label' => 'reports.columns.code', 'priority' => 2, 'card' => 'hide'],
            // Expiry items: the lot written off (empty for other items).
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'batch' => ['label' => 'reports.columns.batch', 'priority' => 2],
            'qty' => ['label' => 'reports.columns.qty_base', 'align' => 'end'],
            'breakdown' => ['label' => 'reports.columns.qty_larger', 'priority' => 2, 'card' => 'hide'],
            'reason' => ['label' => 'reports.columns.reason'],
            'created_by' => ['label' => 'reports.columns.created_by', 'priority' => 2, 'card' => 'hide'],
            'approved_by' => ['label' => 'reports.columns.approved_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        return $this->lines($filters, $user);
    }

    public function row(object $row, array $filters): array
    {
        return $this->commonCells($row) + [
            'expiry_date' => ['text' => $row->expiry_date === null ? '' : Format::date(CarbonImmutable::parse($row->expiry_date))],
            'batch' => ['text' => (string) $row->batch_no, 'ltr' => true],
            'qty' => $this->qtyCell($row->qty_base, $row->product_id) + ['tone' => 'rejected'],
            'breakdown' => $this->breakdown($row->qty_base, $row->product_id),
            'reason' => __('transactions.reasons.'.$row->reason_code),
            'created_by' => (string) $row->created_by_name,
            'approved_by' => (string) $row->approved_by_name,
        ];
    }

    public function summary(array $filters, User $user): array
    {
        $documents = $this->lines($filters, $user)->reorder()->distinct()->count('stock_transaction.id');

        return [['label' => __('reports.damage.documents'), 'value' => Format::qty($documents)]];
    }
}
