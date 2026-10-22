<?php

namespace App\Reports;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\Availability;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock card: every movement of one item in one warehouse over a period, oldest
 * first, with the balance after each, between the opening balance (before the
 * period) and the closing balance (at its end). From stock_movement (history).
 * Without a "Date from" the card starts at the first movement: opening balance 0.
 *
 * A movement is dated, filtered and ordered by its document's date (the date
 * the user gave it, which may be in the past), not by when it was recorded;
 * stock a transfer brings in by the day it was received, in the company's
 * time zone (Availability::movementDay()). Movements of one day keep the order
 * they were recorded in.
 */
final class StockCardReport extends Report
{
    public function key(): string
    {
        return 'stock-card';
    }

    public function icon(): string
    {
        return 'history';
    }

    public function filters(): array
    {
        return [
            Filter::customer(),
            Filter::item(),
            Filter::warehouse(allowAll: false),
            ...Filter::dates(),
        ];
    }

    public function columns(): array
    {
        return [
            'date' => ['label' => 'reports.columns.date'],
            'type' => ['label' => 'reports.columns.type', 'card' => 'badge'],
            'reference' => ['label' => 'reports.columns.reference', 'card' => 'title'],
            'expiry_date' => ['label' => 'reports.columns.expiry_date', 'priority' => 2],
            'in' => ['label' => 'reports.columns.in', 'align' => 'end'],
            'out' => ['label' => 'reports.columns.out', 'align' => 'end'],
            'balance' => ['label' => 'reports.columns.balance', 'align' => 'end'],
        ];
    }

    public function missing(array $filters): ?string
    {
        return $filters['item'] === '' ? __('reports.stock_card.choose_item') : null;
    }

    protected function query(array $filters, User $user): Builder
    {
        $opening = $this->balanceBefore($filters);

        $day = $this->day($filters);

        return $this->inPeriod($filters)
            ->select([
                'stock_movement.id', 'stock_movement.product_id', 'stock_movement.direction', 'stock_movement.qty_base',
                't.doc_no', 't.transaction_type', 'stock_movement.expiry_date',
            ])
            ->selectRaw("$day as movement_day")
            // The balance after each movement: the opening balance plus every movement up to it.
            ->selectRaw("? + sum(case when stock_movement.direction = 'IN' then stock_movement.qty_base else -stock_movement.qty_base end) over (order by $day, stock_movement.seq) as balance", [$opening])
            ->orderByRaw($day)
            ->orderBy('stock_movement.seq');
    }

    public function row(object $row, array $filters): array
    {
        $in = $row->direction === 'IN';
        $type = $row->transaction_type;

        return [
            'date' => Format::date(CarbonImmutable::parse($row->movement_day)),
            'type' => ['text' => __('types.'.config("transactions.types.$type.token")), 'type' => $type],
            'reference' => $this->referenceCell($row->doc_no),
            'expiry_date' => ['text' => $row->expiry_date === null ? '' : Format::date(CarbonImmutable::parse($row->expiry_date))],
            'in' => $in ? $this->qtyCell($row->qty_base, $row->product_id) + ['tone' => 'completed'] : ['text' => '', 'value' => null],
            'out' => $in ? ['text' => '', 'value' => null] : $this->qtyCell($row->qty_base, $row->product_id) + ['tone' => 'rejected'],
            'balance' => $this->qtyCell($row->balance, $row->product_id) + ['strong' => true],
        ];
    }

    public function link(object $row): ?string
    {
        return $this->documentLink($row->transaction_type, $row->doc_no);
    }

    public function summary(array $filters, User $user): array
    {
        if ($this->blocked($filters) !== null) {
            return [];
        }

        $this->loadProducts([$filters['item']]);
        $opening = $this->balanceBefore($filters);
        $totals = $this->inPeriod($filters)
            ->selectRaw("coalesce(sum(case when stock_movement.direction = 'IN' then stock_movement.qty_base end), 0) as qty_in, coalesce(sum(case when stock_movement.direction = 'OUT' then stock_movement.qty_base end), 0) as qty_out")
            ->first();
        $in = (float) $totals?->getAttribute('qty_in');
        $out = (float) $totals?->getAttribute('qty_out');
        $converter = $this->converter($filters['item']);

        return [
            // No + / - signs: the labels say In and Out, and signs read ambiguously right to left.
            ['label' => __('reports.stock_card.opening'), 'value' => $converter->breakdown($opening)],
            ['label' => __('reports.stock_card.total_in'), 'value' => $converter->breakdown($in)],
            ['label' => __('reports.stock_card.total_out'), 'value' => $converter->breakdown($out)],
            ['label' => __('reports.stock_card.closing'), 'value' => $converter->breakdown($opening + $in - $out)],
        ];
    }

    /**
     * The item's movements in the warehouse (scoped: the user's warehouses, their
     * customer), with their document as t.
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockMovement>
     */
    private function movements(array $filters): Builder
    {
        return StockMovement::query()
            ->join('stock_transaction as t', 't.id', '=', 'stock_movement.transaction_id')
            ->where('stock_movement.product_id', $filters['item'])
            ->where('stock_movement.warehouse_id', $filters['warehouse']);
    }

    /**
     * The movements of the chosen days (their documents' dates).
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockMovement>
     */
    private function inPeriod(array $filters): Builder
    {
        $dates = $this->dates($filters);

        return $this->movements($filters)
            ->when($dates->from !== '', fn ($query) => $query->whereRaw($this->day($filters).' >= ?::date', [$dates->from]))
            ->when($dates->to !== '', fn ($query) => $query->whereRaw($this->day($filters).' <= ?::date', [$dates->to]));
    }

    /**
     * The day a movement counts on, in SQL (see the class comment).
     *
     * @param  array<string, string>  $filters
     */
    private function day(array $filters): string
    {
        return Availability::movementDay('stock_movement', 't');
    }

    /**
     * The balance before "Date from"; 0 without one (the card starts at the first movement).
     *
     * @param  array<string, string>  $filters
     */
    private function balanceBefore(array $filters): float
    {
        $from = $this->dates($filters)->from;

        if ($from === '') {
            return 0.0;
        }

        return (float) $this->movements($filters)
            ->whereRaw($this->day($filters).' < ?::date', [$from])
            ->selectRaw("coalesce(sum(case when stock_movement.direction = 'IN' then stock_movement.qty_base else -stock_movement.qty_base end), 0) as qty")
            ->value('qty');
    }
}
