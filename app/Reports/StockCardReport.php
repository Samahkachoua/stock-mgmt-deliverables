<?php

namespace App\Reports;

use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock card: every movement of one item in one warehouse over a period, oldest
 * first, with the balance after each, between the opening balance (before the
 * period) and the closing balance (at its end). From stock_movement (history).
 * Without a "Date from" the card starts at the first movement: opening balance 0.
 *
 * Days are days in the warehouse's own time zone.
 */
final class StockCardReport extends Report
{
    /** @var array<string, string> warehouse id => time zone */
    private array $timezones = [];

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

        return $this->inPeriod($filters)
            ->join('stock_transaction as t', 't.id', '=', 'stock_movement.transaction_id')
            ->select([
                'stock_movement.id', 'stock_movement.product_id', 'stock_movement.direction', 'stock_movement.qty_base', 'stock_movement.created_at',
                't.doc_no', 't.transaction_type', 'stock_movement.expiry_date',
            ])
            // The balance after each movement: the opening balance plus every movement up to it.
            ->selectRaw("? + sum(case when stock_movement.direction = 'IN' then stock_movement.qty_base else -stock_movement.qty_base end) over (order by stock_movement.seq) as balance", [$opening])
            ->orderBy('stock_movement.seq');
    }

    public function row(object $row, array $filters): array
    {
        $in = $row->direction === 'IN';
        $type = $row->transaction_type;

        return [
            'date' => Format::dateTime(CarbonImmutable::parse($row->created_at)->setTimezone($this->timezone($filters))),
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
            ->selectRaw("coalesce(sum(case when direction = 'IN' then qty_base end), 0) as qty_in, coalesce(sum(case when direction = 'OUT' then qty_base end), 0) as qty_out")
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
     * The item's movements in the warehouse (scoped: the user's warehouses, their customer).
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockMovement>
     */
    private function movements(array $filters): Builder
    {
        return StockMovement::query()
            ->where('stock_movement.product_id', $filters['item'])
            ->where('stock_movement.warehouse_id', $filters['warehouse']);
    }

    /**
     * The movements of the chosen days (days in the warehouse's time zone).
     *
     * @param  array<string, string>  $filters
     * @return Builder<StockMovement>
     */
    private function inPeriod(array $filters): Builder
    {
        return $this->dates($filters)->whereInstant($this->movements($filters), 'stock_movement.created_at', $this->timezone($filters));
    }

    /**
     * The balance before "Date from"; 0 without one (the card starts at the first movement).
     *
     * @param  array<string, string>  $filters
     */
    private function balanceBefore(array $filters): float
    {
        $start = $this->dates($filters)->start($this->timezone($filters));

        if ($start === null) {
            return 0.0;
        }

        return (float) $this->movements($filters)
            ->where('stock_movement.created_at', '<', $start)
            ->selectRaw("coalesce(sum(case when direction = 'IN' then qty_base else -qty_base end), 0) as qty")
            ->value('qty');
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function timezone(array $filters): string
    {
        return $this->timezones[$filters['warehouse']] ??= (string) (Warehouse::whereKey($filters['warehouse'])->value('timezone') ?: config('app.timezone'));
    }
}
