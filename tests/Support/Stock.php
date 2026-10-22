<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use App\Support\Transactions\TransactionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Items with no stock; stock is added only by posting documents through
 * PostingService, so balances and movements always agree.
 *
 *   BEANS   Piece, 1 Box = 12, 1 Pallet = 480
 *   YOGURT  Piece, 1 Box = 6, expiry-tracked
 *   PHONE   Piece, serial numbers
 */
final class Stock
{
    /** @var array<string, Product> */
    public array $items = [];

    public readonly User $user;

    private ?User $requester = null;

    private function __construct(
        public readonly Warehouse $warehouse,
        public readonly Customer $customer,
    ) {}

    public static function create(): self
    {
        $stock = new self(
            Warehouse::factory()->create(['code' => 'BEY', 'name' => 'Beirut Main']),
            Customer::factory()->create(['code' => 'ACME', 'name' => 'Acme Foods']),
        );

        $stock->user = userWithRole('ADMIN', $stock->warehouse);
        $units = Unit::pluck('id', 'code');

        foreach ([
            'BEANS' => ['Canned Beans', ['BOX' => 12, 'PLT' => 480], false, false],
            'YOGURT' => ['Yogurt', ['BOX' => 6], true, false],
            'PHONE' => ['Phone', [], false, true],
        ] as $code => [$name, $others, $expiry, $serial]) {
            $product = Product::create([
                'customer_id' => $stock->customer->id, 'sku' => $code, 'name_en' => $name,
                'base_unit_id' => $units['PC'], 'track_expiry' => $expiry, 'track_serial' => $serial,
            ]);
            $product->units()->create(['unit_id' => $units['PC'], 'factor_to_base' => 1, 'is_default' => true]);

            foreach ($others as $unit => $factor) {
                $product->units()->create(['unit_id' => $units[$unit], 'factor_to_base' => $factor, 'is_default' => false]);
            }

            $stock->items[$code] = $product;
        }

        return $stock;
    }

    /**
     * An operator of the fixture warehouse, without any approve permission:
     * creates the Damage / Adjustment documents, so those over their limit
     * wait for approval (an approver's own would be posted at once).
     */
    public function requester(): User
    {
        return $this->requester ??= userWithRole('OPERATOR', $this->warehouse);
    }

    public function id(string $code): string
    {
        return $this->items[$code]->id;
    }

    /**
     * Posts a document of $type with the given lines (keyed r1, r2, ...).
     * Damage and Adjustment need a $reason; they may end up waiting for approval,
     * and are created by requester() unless $by is given. Dated today unless $date is given.
     *
     * @param  list<DocumentLine>  $lines
     */
    public function post(string $type, array $lines, ?User $by = null, ?Warehouse $warehouse = null, ?string $reason = null, ?CarbonImmutable $date = null): StockTransaction
    {
        $keyed = [];
        foreach ($lines as $i => $line) {
            $keyed['r'.($i + 1)] = $line;
        }

        return app(PostingService::class)->post(
            new Document($type, ($warehouse ?? $this->warehouse)->id, $this->customer->id, $date ?? CarbonImmutable::today(), null, $keyed, reason: $reason),
            $by ?? (TransactionTypes::get($type)->supportsApproval() ? $this->requester() : $this->user),
        );
    }

    /**
     * Sends a transfer from the fixture warehouse (or $from) to $to.
     *
     * @param  list<DocumentLine>  $lines
     */
    public function dispatch(array $lines, Warehouse $to, ?User $by = null, ?Warehouse $from = null): StockTransaction
    {
        $keyed = [];
        foreach ($lines as $i => $line) {
            $keyed['r'.($i + 1)] = $line;
        }

        return app(PostingService::class)->post(
            new Document('TRANSFER', ($from ?? $this->warehouse)->id, $this->customer->id, CarbonImmutable::today(), null, $keyed, $to->id),
            $by ?? $this->user,
        );
    }

    /**
     * Base quantity of an item in transit, over all transfers.
     */
    public function inTransit(string $code): float
    {
        return (float) DB::table('in_transit_balance')->where('product_id', $this->id($code))->sum('qty_base');
    }

    /**
     * @param  list<string>  $serials
     */
    public function line(string $code, float|string $qty, string $unit = 'PC', ?string $expiry = null, array $serials = []): DocumentLine
    {
        return new DocumentLine($this->id($code), $unit, $qty, $expiry, $serials);
    }

    /**
     * Balance in base units, optionally of one expiry date (Y-m-d).
     */
    public function balance(string $code, ?string $expiry = null, ?Warehouse $warehouse = null): float
    {
        return (float) DB::table('stock_balance')
            ->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)
            ->where('product_id', $this->id($code))
            ->when($expiry, fn ($query) => $query->where('expiry_date', $expiry))
            ->sum('qty_base');
    }

    /**
     * Transfers whose stock doesn't add up (should be none). What left the source
     * is, by status: all still in transit (In transit); received at the
     * destination as qty_received, the rest recorded short, nothing left in
     * transit (Completed); all back at the source, nothing in transit (Cancelled).
     *
     * @return list<object>
     */
    public static function transitMismatches(): array
    {
        return DB::select(<<<'SQL'
            WITH t AS (
                SELECT st.id, st.doc_no, st.status, st.warehouse_id, st.to_warehouse_id,
                       (SELECT COALESCE(SUM(qty_base), 0) FROM stock_movement m
                         WHERE m.transaction_id = st.id AND m.direction = 'OUT') AS sent,
                       (SELECT COALESCE(SUM(qty_base), 0) FROM stock_movement m
                         WHERE m.transaction_id = st.id AND m.direction = 'IN' AND m.warehouse_id = st.to_warehouse_id) AS arrived,
                       (SELECT COALESCE(SUM(qty_base), 0) FROM stock_movement m
                         WHERE m.transaction_id = st.id AND m.direction = 'IN' AND m.warehouse_id = st.warehouse_id) AS returned,
                       (SELECT COALESCE(SUM(qty_base), 0) FROM in_transit_balance i WHERE i.transaction_id = st.id) AS in_transit,
                       (SELECT COALESCE(SUM(qty_base), 0) FROM stock_transaction_line l WHERE l.transaction_id = st.id) AS lines_sent,
                       (SELECT COALESCE(SUM(qty_received), 0) FROM stock_transaction_line l WHERE l.transaction_id = st.id) AS lines_received
                  FROM stock_transaction st
                 WHERE st.to_warehouse_id IS NOT NULL
            )
            SELECT * FROM t
             WHERE sent <> lines_sent
                OR (status = 'DISPATCHED' AND (in_transit <> sent OR arrived <> 0 OR returned <> 0))
                OR (status = 'POSTED' AND (in_transit <> 0 OR returned <> 0 OR arrived <> lines_received OR arrived > sent))
                OR (status = 'CANCELLED' AND (in_transit <> 0 OR arrived <> 0 OR returned <> sent))
        SQL);
    }

    /**
     * Rows where stock_balance differs from the sum of its movements (should be none).
     *
     * @return list<object>
     */
    public static function ledgerMismatches(): array
    {
        return DB::select(<<<'SQL'
            WITH ledger AS (
                SELECT warehouse_id, customer_id, product_id,
                       COALESCE(expiry_date, DATE '0001-01-01') AS expiry_key,
                       SUM(CASE WHEN direction = 'IN' THEN qty_base ELSE -qty_base END) AS qty
                  FROM stock_movement
                 GROUP BY 1, 2, 3, 4
            )
            SELECT COALESCE(b.warehouse_id, l.warehouse_id) AS warehouse_id,
                   COALESCE(b.product_id, l.product_id) AS product_id,
                   COALESCE(b.qty_base, 0) AS balance, COALESCE(l.qty, 0) AS movements
              FROM stock_balance b
              FULL JOIN ledger l
                ON l.warehouse_id = b.warehouse_id AND l.customer_id = b.customer_id
               AND l.product_id = b.product_id AND l.expiry_key = b.expiry_key
             WHERE COALESCE(b.qty_base, 0) <> COALESCE(l.qty, 0)
        SQL);
    }
}
