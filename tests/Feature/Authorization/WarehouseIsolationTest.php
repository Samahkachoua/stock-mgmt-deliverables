<?php

use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use App\Models\Customer;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Inserts a stock document directly (no scopes), as PostingService will later.
 */
function documentIn(Warehouse $warehouse, Customer $customer, ?Warehouse $to = null): string
{
    return (string) DB::table('stock_transaction')->insertGetId([
        'doc_no' => 'DOC-'.Str::upper(Str::random(8)),
        'transaction_type' => $to ? 'TRANSFER' : 'IN',
        'warehouse_id' => $warehouse->id,
        'to_warehouse_id' => $to?->id,
        'customer_id' => $customer->id,
        'created_by' => userWithRole('ADMIN')->id,
    ], 'id');
}

beforeEach(function () {
    $this->beirut = Warehouse::factory()->create(['name' => 'Beirut Main']);
    $this->tripoli = Warehouse::factory()->create(['name' => 'Tripoli']);
    $this->operator = userWithRole('OPERATOR', $this->beirut);
});

it('does not let an operator choose an unassigned warehouse on a form', function () {
    Livewire::actingAs($this->operator)
        ->test(TransactionForm::class, ['type' => 'IN'])
        ->call('chooseWarehouse', $this->tripoli->id)
        ->assertSet('warehouseId', $this->beirut->id);
});

it('ignores an unassigned warehouse put in a list filter', function () {
    Livewire::actingAs($this->operator)
        ->test(TransactionList::class, ['type' => 'IN'])
        ->set('warehouse', $this->tripoli->id)
        ->assertSet('warehouse', $this->beirut->id)
        ->set('warehouse', 'all')
        ->assertSet('warehouse', 'all')
        ->assertDontSee('Tripoli');
});

it('ignores a warehouse put in the URL', function () {
    $tripoliDocument = documentIn($this->tripoli, Customer::factory()->create());
    $reference = DB::table('stock_transaction')->where('id', $tripoliDocument)->value('doc_no');

    $this->actingAs($this->operator)
        ->get(route('receive', ['warehouse_id' => $this->tripoli->id, 'warehouse' => $this->tripoli->id]))
        ->assertOk()
        ->assertDontSee($reference);
});

it('does not return documents of an unassigned warehouse', function () {
    $customer = Customer::factory()->create();
    $own = documentIn($this->beirut, $customer);
    $other = documentIn($this->tripoli, $customer);

    $this->actingAs($this->operator);

    expect(StockTransaction::pluck('id')->all())->toBe([$own])
        ->and(StockTransaction::find($other))->toBeNull()
        ->and(StockTransaction::where('warehouse_id', $this->tripoli->id)->exists())->toBeFalse()
        ->and(StockTransaction::whereKey($other)->count())->toBe(0);
});

it('shows a transfer to both its source and destination warehouse', function () {
    $customer = Customer::factory()->create();
    $incoming = documentIn($this->tripoli, $customer, to: $this->beirut);

    $this->actingAs($this->operator);

    expect(StockTransaction::find($incoming))->not->toBeNull();
});
