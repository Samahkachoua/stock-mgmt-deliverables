<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Race;
use Tests\Support\Stock;

/*
 * Two separate processes press "Confirm receipt" on the same transfer at the
 * same moment. The stock may arrive only once.
 *
 * Commits real rows (no RefreshDatabase in this folder) and cleans them up.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true]);

    $this->source = Warehouse::factory()->create();
    $this->destination = Warehouse::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->sender = userWithRole('OPERATOR', $this->source);
    $this->receiver = userWithRole('OPERATOR', $this->destination);
    $this->product = Product::create([
        'customer_id' => $this->customer->id, 'sku' => 'RACE-'.uniqid(), 'name_en' => 'Contested item',
        'base_unit_id' => Unit::where('code', 'PC')->value('id'),
    ]);
    $this->product->units()->create(['unit_id' => $this->product->base_unit_id, 'factor_to_base' => 1, 'is_default' => true]);

    $posting = app(PostingService::class);
    $posting->post(
        new Document('IN', $this->source->id, $this->customer->id, CarbonImmutable::today(), null, ['r1' => new DocumentLine($this->product->id, 'PC', 10)]),
        $this->sender,
    );
    $this->transfer = $posting->post(
        new Document('TRANSFER', $this->source->id, $this->customer->id, CarbonImmutable::today(), null, ['r1' => new DocumentLine($this->product->id, 'PC', 10)], $this->destination->id),
        $this->sender,
    );
});

afterEach(function () {
    $transactions = DB::table('stock_transaction')->where('customer_id', $this->customer->id)->pluck('id');
    $lines = DB::table('stock_transaction_line')->whereIn('transaction_id', $transactions)->pluck('id');
    $users = [$this->sender->id, $this->receiver->id];

    DB::table('audit_log')->whereIn('entity_id', $transactions)->delete();
    DB::table('in_transit_balance')->whereIn('transaction_id', $transactions)->delete();
    DB::table('stock_movement')->whereIn('transaction_id', $transactions)->delete();
    DB::table('stock_transaction_line_serial')->whereIn('line_id', $lines)->delete();
    DB::table('stock_transaction_line')->whereIn('id', $lines)->delete();
    DB::table('stock_transaction')->whereIn('id', $transactions)->delete();
    DB::table('stock_balance')->where('customer_id', $this->customer->id)->delete();
    DB::table('product_unit')->where('product_id', $this->product->id)->delete();
    DB::table('product')->where('id', $this->product->id)->delete();
    DB::table('user_role')->whereIn('user_id', $users)->delete();
    DB::table('user_warehouse')->whereIn('user_id', $users)->delete();
    DB::table('app_user')->whereIn('id', $users)->delete();
    DB::table('customer')->where('id', $this->customer->id)->delete();
    DB::table('warehouse')->whereIn('id', [$this->source->id, $this->destination->id])->delete();
});

it('posts a transfer receipt only once when confirmed twice at the same moment', function () {
    $results = Race::run(__DIR__.'/receive-worker.php', [
        'transfer' => $this->transfer->id,
        'line' => DB::table('stock_transaction_line')->where('transaction_id', $this->transfer->id)->value('id'),
        'qty' => 10,
        'user' => $this->receiver->id,
    ]);

    $succeeded = array_filter($results, fn ($result) => $result['ok']);
    $failed = array_values(array_filter($results, fn ($result) => ! $result['ok']));

    expect($succeeded)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['message'])->toContain('already received by');

    $ins = DB::table('stock_movement')->where('transaction_id', $this->transfer->id)->where('direction', 'IN')->get();

    expect($ins)->toHaveCount(1)
        ->and((float) $ins[0]->qty_base)->toBe(10.0)
        ->and((float) DB::table('stock_balance')->where('product_id', $this->product->id)->where('warehouse_id', $this->destination->id)->sum('qty_base'))->toBe(10.0)
        ->and((float) DB::table('stock_balance')->where('product_id', $this->product->id)->where('warehouse_id', $this->source->id)->sum('qty_base'))->toBe(0.0)
        ->and(DB::table('in_transit_balance')->where('transaction_id', $this->transfer->id)->count())->toBe(0)
        ->and(DB::table('audit_log')->where('entity_id', $this->transfer->id)->where('action', 'RECEIVE')->count())->toBe(1)
        ->and(Stock::ledgerMismatches())->toBe([]);
});
