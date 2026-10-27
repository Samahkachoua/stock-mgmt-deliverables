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
use App\Services\Stock\ReceivedLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A known dataset for the report tests, posted through PostingService so that
 * movements, balances and in-transit stock all agree. Two warehouses, two
 * customers:
 *
 *   Beirut Main (BEY)
 *     Acme   BEANS   receive 60, deliver 12, damage 6 (HANDLING), count 42→40 (−2),
 *                    transfer 12 to Tripoli (in transit)              → 28
 *            YOGURT  +10 days 12, expired (−2 days) 6, +100 days 6;
 *                    damage 4 (EXPIRED) takes the expired first       → 20 (expired 2)
 *            PHONE   SN-1, SN-2                                       → 2
 *     Cedar  RICE    receive 10, count 10→12 (+2), transfer 3 to Tripoli,
 *                    2 received (1 MISSING)                           → 9
 *   Tripoli (TRP)
 *     Cedar  RICE    receive 7, 2 from Beirut, damage 1 (TRANSPORT)   → 8
 *     Acme   BEANS   12 arriving from Beirut
 *
 * Users: $admin (both warehouses), $beirutManager and $tripoliOperator (one
 * warehouse each), $auditorTripoli (audit.view, Tripoli only) and $acmePortal
 * (Acme's customer login, Beirut, with the auditor's report permissions).
 */
final class ReportData
{
    public Stock $stock;

    public Warehouse $beirut;

    public Warehouse $tripoli;

    public Customer $acme;

    public Customer $cedar;

    public Product $rice;

    public User $admin;

    public User $beirutManager;

    public User $tripoliOperator;

    public User $auditorTripoli;

    public User $acmePortal;

    public StockTransaction $transferInTransit;

    public StockTransaction $shortTransfer;

    public static function create(): self
    {
        $data = new self;
        $data->stock = $stock = Stock::create();
        $data->beirut = $stock->warehouse;
        $data->acme = $stock->customer;
        $data->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
        $data->cedar = Customer::factory()->create(['code' => 'CEDAR', 'name' => 'Cedar Trading']);

        $data->admin = $stock->user;
        $data->admin->warehouses()->attach($data->tripoli->id);
        $data->beirutManager = userWithRole('MANAGER', $data->beirut);
        $data->tripoliOperator = userWithRole('OPERATOR', $data->tripoli);
        $data->auditorTripoli = userWithRole('AUDITOR', $data->tripoli);
        $data->acmePortal = userWithRole('AUDITOR', $data->beirut);
        $data->acmePortal->forceFill(['customer_id' => $data->acme->id])->save();

        $units = Unit::pluck('id', 'code');
        $data->rice = Product::create([
            'customer_id' => $data->cedar->id, 'sku' => 'RICE', 'name_en' => 'Rice 5kg', 'name_ar' => 'أرز ٥ كغ',
            'base_unit_id' => $units['PC'], 'track_expiry' => false, 'track_serial' => false,
        ]);
        $data->rice->units()->create(['unit_id' => $units['PC'], 'factor_to_base' => 1, 'is_default' => true]);

        $data->post();

        return $data;
    }

    private function post(): void
    {
        $stock = $this->stock;
        $stock->post('IN', [
            $stock->line('BEANS', 5, 'BOX'),
            $stock->line('YOGURT', 12, expiry: now()->addDays(10)->toDateString()),
            $stock->line('YOGURT', 6, expiry: now()->subDays(2)->toDateString()),
            $stock->line('YOGURT', 6, expiry: now()->addDays(100)->toDateString()),
            $stock->line('PHONE', 2, serials: ['SN-1', 'SN-2']),
        ]);
        $stock->post('OUT', [$stock->line('BEANS', 12)]);
        $stock->post('DAMAGE', [$stock->line('BEANS', 6)], reason: 'HANDLING');
        $stock->post('DAMAGE', [$stock->line('YOGURT', 4)], reason: 'EXPIRED');
        $stock->post('ADJUSTMENT', [$stock->line('BEANS', '40')], reason: 'LOSS_THEFT');

        $this->cedarDocument('IN', $this->beirut, [new DocumentLine($this->rice->id, $this->riceUnit(), 10)]);
        $this->cedarDocument('ADJUSTMENT', $this->beirut, [new DocumentLine($this->rice->id, $this->riceUnit(), '12')], reason: 'STOCK_COUNT_VARIANCE');
        $this->cedarDocument('IN', $this->tripoli, [new DocumentLine($this->rice->id, $this->riceUnit(), 7)]);
        $this->cedarDocument('DAMAGE', $this->tripoli, [new DocumentLine($this->rice->id, $this->riceUnit(), 1)], reason: 'TRANSPORT');

        $this->transferInTransit = $stock->dispatch([$stock->line('BEANS', 1, 'BOX')], $this->tripoli);

        $this->shortTransfer = $this->cedarDocument('TRANSFER', $this->beirut, [new DocumentLine($this->rice->id, $this->riceUnit(), 3)], to: $this->tripoli);
        $line = DB::table('stock_transaction_line')->where('transaction_id', $this->shortTransfer->id)->value('id');
        app(PostingService::class)->receive($this->shortTransfer, [$line => new ReceivedLine('2', 'MISSING')], $this->tripoliOperator);
    }

    /**
     * @param  list<DocumentLine>  $lines
     */
    private function cedarDocument(string $type, Warehouse $warehouse, array $lines, ?string $reason = null, ?Warehouse $to = null): StockTransaction
    {
        $keyed = [];
        foreach ($lines as $i => $line) {
            $keyed['r'.($i + 1)] = $line;
        }

        return app(PostingService::class)->post(
            new Document($type, $warehouse->id, $this->cedar->id, CarbonImmutable::today(), null, $keyed, $to?->id, $reason),
            $this->admin,
        );
    }

    private function riceUnit(): string
    {
        return 'PC';
    }
}
