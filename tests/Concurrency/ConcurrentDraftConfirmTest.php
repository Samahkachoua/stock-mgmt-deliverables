<?php

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\PostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Race;
use Tests\Support\Stock;

/*
 * Two separate processes, each with its own database connection, confirm the
 * same order at the same moment. Exactly one may: the draft becomes one
 * delivery note, which takes over the order's reservation once (no stock
 * leaves until it is delivered).
 *
 * This test commits real rows, so it can't run inside RefreshDatabase's
 * transaction (tests/Pest.php gives this folder no database trait). It cleans
 * up everything it created.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--force' => true]);

    $this->warehouse = Warehouse::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->branch = CustomerBranch::create(['customer_id' => $this->customer->id, 'code' => 'RACE', 'name' => 'Race branch']);
    $this->user = userWithRole('MANAGER', $this->warehouse);
    $this->product = Product::create([
        'customer_id' => $this->customer->id, 'sku' => 'RACE-'.uniqid(), 'name_en' => 'Contested item',
        'base_unit_id' => Unit::where('code', 'PC')->value('id'),
    ]);
    $this->product->units()->create(['unit_id' => $this->product->base_unit_id, 'factor_to_base' => 1, 'is_default' => true]);

    app(PostingService::class)->post(
        new Document('IN', $this->warehouse->id, $this->customer->id, CarbonImmutable::today(), null, ['r1' => new DocumentLine($this->product->id, 'PC', 10)]),
        $this->user,
    );

    $this->draft = app(DraftService::class)->save(new DraftInput('OUT', $this->customer->id, $this->branch->id, $this->warehouse->id, CarbonImmutable::today(), null, [
        'r1' => new DraftLineInput($this->product->id, 'PC', 10),
    ], deliveryDate: CarbonImmutable::today(), priority: 'NORMAL'), $this->user);
});

afterEach(function () {
    $transactions = DB::table('stock_transaction')->where('customer_id', $this->customer->id)->pluck('id');
    $lines = DB::table('stock_transaction_line')->whereIn('transaction_id', $transactions)->pluck('id');
    $drafts = DB::table('stock_draft')->where('customer_id', $this->customer->id)->pluck('id');

    DB::table('stock_reservation')->where('customer_id', $this->customer->id)->delete();
    DB::table('audit_log')->where('entity', 'stock_transaction')->whereIn('entity_id', $transactions)->delete();
    DB::table('audit_log')->where('entity', 'stock_draft')->whereIn('entity_id', $drafts)->delete();
    DB::table('stock_movement')->whereIn('transaction_id', $transactions)->delete();
    DB::table('stock_transaction_line')->whereIn('id', $lines)->delete();
    DB::table('stock_transaction')->whereIn('id', $transactions)->delete();
    DB::table('stock_draft_line')->whereIn('draft_id', $drafts)->delete();
    DB::table('stock_draft')->whereIn('id', $drafts)->delete();
    DB::table('stock_balance')->where('customer_id', $this->customer->id)->delete();
    DB::table('customer_branch')->where('id', $this->branch->id)->delete();
    DB::table('product_unit')->where('product_id', $this->product->id)->delete();
    DB::table('product')->where('id', $this->product->id)->delete();
    DB::table('user_role')->where('user_id', $this->user->id)->delete();
    DB::table('user_warehouse')->where('user_id', $this->user->id)->delete();
    DB::table('app_user')->where('id', $this->user->id)->delete();
    DB::table('customer')->where('id', $this->customer->id)->delete();
    DB::table('warehouse')->where('id', $this->warehouse->id)->delete();
});

it('confirms a draft once when two people confirm it at the same moment', function () {
    $results = Race::run(__DIR__.'/confirm-draft-worker.php', ['draft' => $this->draft->id, 'user' => $this->user->id]);

    $succeeded = array_filter($results, fn ($result) => $result['ok']);
    $failed = array_filter($results, fn ($result) => ! $result['ok']);

    expect($succeeded)->toHaveCount(1)
        ->and($failed)->toHaveCount(1)
        ->and(array_values($failed)[0]['error'])->toBe('AlreadyProcessed')
        ->and(DB::table('stock_transaction')->where('draft_id', $this->draft->id)->count())->toBe(1)
        ->and(DB::table('stock_movement')->where('product_id', $this->product->id)->where('direction', 'OUT')->exists())->toBeFalse()
        ->and((float) DB::table('stock_balance')->where('product_id', $this->product->id)->sum('qty_base'))->toBe(10.0)
        ->and((float) DB::table('stock_balance')->where('product_id', $this->product->id)->sum('qty_reserved'))->toBe(10.0)
        ->and(DB::table('stock_reservation')->where('customer_id', $this->customer->id)->whereNotNull('line_id')->count())->toBe(1)
        ->and(DB::table('stock_draft')->where('id', $this->draft->id)->value('status'))->toBe('POSTED')
        ->and(Stock::ledgerMismatches())->toBe([]);
});
