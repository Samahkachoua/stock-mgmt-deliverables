<?php

namespace App\Support\Home;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockDraft;
use App\Models\User;
use App\Support\CompanyTime;
use App\Support\Days;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Home for a customer user (app_user.customer_id): their orders and their
 * stock in the chosen warehouses. Every query goes through the scoped models,
 * so it only ever sees their own customer (and branches).
 *
 * Quantities of different items are never added together: figures are items
 * and lots (expiry date + batch).
 *
 * @phpstan-import-type Card from StaffHome
 *
 * @phpstan-type Bucket array{key: string, label: string, lots: int, tone: string}
 * @phpstan-type Lot array{item: string, batch: string, qty: string, date: string, left: int, tone: string}
 * @phpstan-type DraftRow array{number: string, kind: DraftUi, status: string, line: string, url: string}
 */
final class CustomerHome
{
    /** "Expiring" in the attention cards: within this many days. */
    public const EXPIRY_DAYS = 30;

    /** The snapshot's "Expiring in … days" and the last bucket of its bar. */
    public const HORIZON_DAYS = 90;

    /** Rows of "Expiring soonest" and of "Your open orders". */
    public const ROWS = 5;

    private readonly CarbonImmutable $today;

    /**
     * @param  list<string>  $warehouseIds  the warehouses Home shows (one, or all the user's)
     */
    public function __construct(private readonly User $user, private readonly array $warehouseIds)
    {
        $this->today = CompanyTime::today();
    }

    /**
     * "Needs your attention": lots expiring soon, orders on their way, orders
     * that can still be changed.
     *
     * @return list<Card>
     */
    public function cards(): array
    {
        $cards = [];

        if ($this->user->hasPermission('stock.view')) {
            $expiring = StaffHome::expiring($this->warehouseIds, self::EXPIRY_DAYS);
            $cards[] = $this->card('expiring', 'warning', 'rejected', 'expiring', $expiring['lots'],
                trans_choice('home.customer.cards.expiring', $expiring['lots'], ['days' => Format::qty(self::EXPIRY_DAYS)]),
                $expiring['first'] === null ? '' : __('home.customer.cards.expiring_note', $expiring['first']),
                'view_stock');
        }

        $orders = $this->orders();
        if ($orders !== null) {
            $onWay = $this->onTheWay($orders);
            $first = $onWay->clone()->orderBy('stock_draft.delivery_date')->first();
            $count = $onWay->count();
            $cards[] = $this->card('on_way', 'local_shipping', 'transit', 'on_way', $count,
                trans_choice('home.customer.cards.on_way', $count),
                $first === null ? '' : __('home.customer.cards.on_way_note', [
                    'number' => $first->doc_no,
                    'lines' => trans_choice('home.lines', (int) $first->getAttribute('line_count'), ['count' => Format::qty((int) $first->getAttribute('line_count'))]),
                ]),
                'track');

            $editable = $this->openDrafts($orders)->count();
            $cards[] = $this->card('editable', 'edit', 'pending', 'open', $editable,
                trans_choice('home.customer.cards.editable', $editable),
                __('home.customer.cards.editable_note'),
                'open_list');
        }

        return $cards;
    }

    /**
     * "Your stock snapshot": items and lots in stock, lots expiring within
     * HORIZON_DAYS, and every lot with a date by how long it has left.
     *
     * @return array{items: int, lots: int, expiring: int, buckets: list<Bucket>}|null
     */
    public function snapshot(): ?array
    {
        if (! $this->user->hasPermission('stock.view')) {
            return null;
        }

        $stock = $this->stock();
        $today = $this->today->toDateString();
        $day = fn (int $days) => $this->today->addDays($days)->toDateString();

        $counts = $stock->clone()
            ->whereNotNull('stock_balance.expiry_date')
            ->selectRaw('count(*) FILTER (WHERE expiry_date < ?) as expired', [$today])
            ->selectRaw('count(*) FILTER (WHERE expiry_date BETWEEN ? AND ?) as d30', [$today, $day(30)])
            ->selectRaw('count(*) FILTER (WHERE expiry_date BETWEEN ? AND ?) as d60', [$day(31), $day(60)])
            ->selectRaw('count(*) FILTER (WHERE expiry_date BETWEEN ? AND ?) as d90', [$day(61), $day(90)])
            ->selectRaw('count(*) FILTER (WHERE expiry_date > ?) as later', [$day(90)])
            ->toBase()
            ->first();

        $buckets = [
            ['key' => 'expired', 'lots' => (int) $counts->expired, 'tone' => 'cancelled'],
            ['key' => 'd30', 'lots' => (int) $counts->d30, 'tone' => 'rejected'],
            ['key' => 'd60', 'lots' => (int) $counts->d60, 'tone' => 'pending'],
            ['key' => 'd90', 'lots' => (int) $counts->d90, 'tone' => 'transit'],
            ['key' => 'later', 'lots' => (int) $counts->later, 'tone' => 'completed'],
        ];

        return [
            'items' => $stock->clone()->distinct()->count('stock_balance.product_id'),
            'lots' => $stock->clone()->whereNotNull('stock_balance.expiry_date')->count(),
            'expiring' => (int) $counts->d30 + (int) $counts->d60 + (int) $counts->d90,
            // Expired lots only when there are some: a bar segment of 0 says nothing.
            'buckets' => array_values(array_map(
                fn (array $bucket) => $bucket + ['label' => __('home.customer.snapshot.buckets.'.$bucket['key'])],
                array_filter($buckets, fn (array $bucket) => $bucket['key'] !== 'expired' || $bucket['lots'] > 0),
            )),
        ];
    }

    /**
     * "Expiring soonest": the lots with stock that expire first, from today.
     *
     * @return list<Lot>
     */
    public function soonest(): array
    {
        if (! $this->user->hasPermission('stock.view')) {
            return [];
        }

        $rows = $this->stock()
            ->whereNotNull('stock_balance.expiry_date')
            ->where('stock_balance.expiry_date', '>=', $this->today->toDateString())
            ->orderBy('stock_balance.expiry_date')
            ->orderBy('stock_balance.batch_key')
            ->limit(self::ROWS)
            ->get(['stock_balance.product_id', 'stock_balance.expiry_date', 'stock_balance.batch_no', 'stock_balance.qty_base']);

        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique()->all())->get()->keyBy('id');

        return $rows->map(function (StockBalance $row) use ($products) {
            /** @var Product $product */
            $product = $products[$row->product_id];
            $left = Days::until((string) $row->expiry_date, CompanyTime::zone());

            return [
                'item' => $product->name(),
                'batch' => (string) $row->getAttribute('batch_no'),
                'qty' => Format::qtyUnit((float) $row->qty_base, $product->converter()->base()),
                'date' => Format::date(CarbonImmutable::parse($row->expiry_date)),
                'left' => $left,
                'tone' => $left <= self::EXPIRY_DAYS ? 'rejected' : 'pending',
            ];
        })->values()->all();
    }

    /**
     * "Your open orders": orders not confirmed yet, last saved first.
     *
     * @return list<DraftRow>
     */
    public function openOrders(): array
    {
        $orders = $this->orders();

        if ($orders === null) {
            return [];
        }

        return $this->openDrafts($orders)
            ->orderByDesc('stock_draft.updated_at')
            ->limit(self::ROWS)
            ->get()
            ->map(fn (StockDraft $row) => [
                'number' => (string) $row->doc_no,
                'kind' => $orders,
                'status' => (string) $row->status,
                'line' => __('home.customer.orders.line', [
                    'lines' => trans_choice('home.lines', (int) $row->getAttribute('line_count'), ['count' => Format::qty((int) $row->getAttribute('line_count'))]),
                    'saved' => Format::dateTime(CarbonImmutable::parse($row->getAttribute('updated_at'))),
                ]),
                'url' => $orders->route('show', $row->doc_no),
            ])
            ->values()
            ->all();
    }

    /**
     * Orders, for a user who may open them; null otherwise.
     */
    public function orders(): ?DraftUi
    {
        $orders = DraftTypes::all()['OUT'] ?? null;

        return $orders !== null && Gate::forUser($this->user)->allows('drafts.out.open') ? $orders : null;
    }

    /**
     * Orders whose delivery note is on its way (dispatched, not delivered).
     *
     * @return Builder<StockDraft>
     */
    public function onTheWay(DraftUi $orders): Builder
    {
        return $this->withLines(StockDraft::query()
            ->where('stock_draft.transaction_type', $orders->code)
            ->whereIn('stock_draft.warehouse_id', $this->warehouseIds)
            ->whereExists(fn ($query) => $query->from('stock_transaction as note')
                ->whereColumn('note.draft_id', 'stock_draft.id')
                ->where('note.status', 'DISPATCHED')));
    }

    /**
     * Orders not confirmed yet: the customer can still change or cancel them.
     *
     * @return Builder<StockDraft>
     */
    public function openDrafts(DraftUi $orders): Builder
    {
        return $this->withLines(StockDraft::query()
            ->where('stock_draft.transaction_type', $orders->code)
            ->where('stock_draft.status', 'DRAFT')
            ->whereIn('stock_draft.warehouse_id', $this->warehouseIds));
    }

    /**
     * @return Card
     */
    private function card(string $key, string $icon, string $tone, string $chip, int $count, string $label, string $subtitle, string $action): array
    {
        return [
            'key' => $key,
            'icon' => $icon,
            'tone' => $tone,
            'chip' => __('home.chips.'.$chip),
            'count' => $count,
            'label' => $label,
            'subtitle' => $subtitle,
            'action' => __('home.actions.'.$action),
        ];
    }

    /**
     * The customer's stock (lots with a quantity) in Home's warehouses.
     *
     * @return Builder<StockBalance>
     */
    private function stock(): Builder
    {
        return StockBalance::query()
            ->whereIn('stock_balance.warehouse_id', $this->warehouseIds)
            ->where('stock_balance.qty_base', '>', 0);
    }

    /**
     * @param  Builder<StockDraft>  $query
     * @return Builder<StockDraft>
     */
    private function withLines(Builder $query): Builder
    {
        return $query
            ->select(['stock_draft.id', 'stock_draft.doc_no', 'stock_draft.status', 'stock_draft.delivery_date', 'stock_draft.updated_at'])
            ->selectSub(DB::table('stock_draft_line')->whereColumn('draft_id', 'stock_draft.id')->selectRaw('count(*)'), 'line_count');
    }
}
