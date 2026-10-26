<?php

namespace App\Reports;

use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Adjustments: counts that changed stock, by period and reason, with what the
 * system had, what was counted and the difference; gains and losses apart.
 */
final class AdjustmentsReport extends PostedLinesReport
{
    public function key(): string
    {
        return 'adjustments';
    }

    public function icon(): string
    {
        return 'tune';
    }

    protected function type(): string
    {
        return 'ADJUSTMENT';
    }

    public function filters(): array
    {
        return [
            ...parent::filters(),
            Filter::select('direction', 'reports.adjustments.direction', [
                '' => 'reports.adjustments.both',
                'IN' => 'reports.adjustments.gains',
                'OUT' => 'reports.adjustments.losses',
            ]),
        ];
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
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'batch' => ['label' => 'reports.columns.batch', 'priority' => 2],
            'system' => ['label' => 'reports.columns.in_system', 'align' => 'end', 'priority' => 2],
            'counted' => ['label' => 'reports.columns.counted', 'align' => 'end'],
            'difference' => ['label' => 'reports.columns.difference', 'align' => 'end', 'card' => 'badge'],
            'reason' => ['label' => 'reports.columns.reason', 'priority' => 2],
            'approved_by' => ['label' => 'reports.columns.approved_by', 'priority' => 2, 'card' => 'hide'],
        ];
    }

    protected function query(array $filters, User $user): Builder
    {
        return $this->lines($filters, $user)
            ->when($filters['direction'] !== '', fn ($query) => $query->where('l.direction', $filters['direction']));
    }

    public function row(object $row, array $filters): array
    {
        $difference = $row->direction === 'IN' ? (float) $row->qty_base : -(float) $row->qty_base;

        return $this->commonCells($row) + [
            'expiry_date' => ['text' => $row->expiry_date === null ? '' : Format::date(CarbonImmutable::parse($row->expiry_date))],
            'batch' => ['text' => (string) $row->batch_no, 'ltr' => true],
            'system' => $this->qtyCell($row->qty_system, $row->product_id),
            'counted' => $this->qtyCell($row->qty_counted, $row->product_id) + ['strong' => true],
            'difference' => $this->qtyCell($difference, $row->product_id, signed: true) + ['tone' => $difference > 0 ? 'completed' : 'rejected'],
            'reason' => __('transactions.reasons.'.$row->reason_code),
            'approved_by' => (string) $row->approved_by_name,
        ];
    }

    /**
     * How many lines gained and lost stock (with the filters, direction aside).
     */
    public function summary(array $filters, User $user): array
    {
        $counts = $this->lines($filters, $user)->reorder()
            ->groupBy('l.direction')
            ->select(DB::raw('l.direction, count(*) as lines'))
            ->get()
            ->pluck('lines', 'direction');

        return [
            ['label' => __('reports.adjustments.gains'), 'value' => trans_choice('reports.lines', (int) ($counts['IN'] ?? 0), ['count' => Format::qty((int) ($counts['IN'] ?? 0))])],
            ['label' => __('reports.adjustments.losses'), 'value' => trans_choice('reports.lines', (int) ($counts['OUT'] ?? 0), ['count' => Format::qty((int) ($counts['OUT'] ?? 0))])],
        ];
    }
}
