<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\WarehouseSwitcher;
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

it('does not let an operator switch to an unassigned warehouse', function () {
    Livewire::actingAs($this->operator)
        ->test(WarehouseSwitcher::class)
        ->call('select', $this->tripoli->id)
        ->assertForbidden();

    expect(session(EnsureCurrentWarehouse::SESSION_KEY))->not->toBe($this->tripoli->id);
});

it('ignores an unassigned warehouse planted in the session', function () {
    $this->actingAs($this->operator)
        ->withSession([EnsureCurrentWarehouse::SESSION_KEY => $this->tripoli->id])
        ->get(route('receive'))
        ->assertOk()
        ->assertSessionHas(EnsureCurrentWarehouse::SESSION_KEY, $this->beirut->id)
        ->assertDontSee('Tripoli');
});

it('ignores a warehouse put in the URL', function () {
    $this->actingAs($this->operator)
        ->get(route('receive', ['warehouse_id' => $this->tripoli->id, 'warehouse' => $this->tripoli->id]))
        ->assertOk()
        ->assertSessionHas(EnsureCurrentWarehouse::SESSION_KEY, $this->beirut->id);
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
