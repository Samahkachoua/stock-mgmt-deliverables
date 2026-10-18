<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function productOf(Customer $customer): string
{
    return (string) DB::table('product')->insertGetId([
        'customer_id' => $customer->id,
        'sku' => 'SKU-'.Str::upper(Str::random(6)),
        'name_en' => 'Item of '.$customer->name,
        'base_unit_id' => DB::table('unit')->where('code', 'PC')->value('id'),
    ], 'id');
}

function documentFor(Customer $customer, Warehouse $warehouse): string
{
    return (string) DB::table('stock_transaction')->insertGetId([
        'doc_no' => 'DOC-'.Str::upper(Str::random(8)),
        'transaction_type' => 'IN',
        'warehouse_id' => $warehouse->id,
        'customer_id' => $customer->id,
        'created_by' => userWithRole('ADMIN')->id,
    ], 'id');
}

function customerUser(?Customer $customer, Warehouse $warehouse): User
{
    $user = userWithRole('CUSTOMER', $warehouse);
    $user->forceFill(['is_customer_user' => true, 'customer_id' => $customer?->id])->save();

    return $user->fresh();
}

beforeEach(function () {
    $this->warehouse = Warehouse::factory()->create();
    $this->acme = Customer::factory()->create(['name' => 'Acme']);
    $this->cedar = Customer::factory()->create(['name' => 'Cedar']);

    $this->acmeProduct = productOf($this->acme);
    $this->cedarProduct = productOf($this->cedar);
    $this->acmeDocument = documentFor($this->acme, $this->warehouse);
    $this->cedarDocument = documentFor($this->cedar, $this->warehouse);
});

it('shows a customer user only their own customer, items and documents', function () {
    $this->actingAs(customerUser($this->acme, $this->warehouse));

    expect(Customer::pluck('id')->all())->toBe([$this->acme->id])
        ->and(Customer::find($this->cedar->id))->toBeNull()
        ->and(Product::pluck('id')->all())->toBe([$this->acmeProduct])
        ->and(Product::find($this->cedarProduct))->toBeNull()
        ->and(StockTransaction::pluck('id')->all())->toBe([$this->acmeDocument])
        ->and(StockTransaction::where('customer_id', $this->cedar->id)->exists())->toBeFalse();
});

it('shows a customer login without a customer nothing at all', function () {
    $this->actingAs(customerUser(null, $this->warehouse));

    expect(Customer::count())->toBe(0)
        ->and(Product::count())->toBe(0)
        ->and(StockTransaction::count())->toBe(0);
});

it('does not limit staff users to one customer', function () {
    $this->actingAs(userWithRole('OPERATOR', $this->warehouse));

    expect(Customer::count())->toBe(2)
        ->and(Product::count())->toBe(2)
        ->and(StockTransaction::count())->toBe(2)
        ->and(StockTransaction::where('customer_id', $this->cedar->id)->count())->toBe(1);
});

it('still applies the warehouse limit to customer users', function () {
    $elsewhere = Warehouse::factory()->create();
    documentFor($this->acme, $elsewhere);

    $this->actingAs(customerUser($this->acme, $this->warehouse));

    expect(StockTransaction::pluck('id')->all())->toBe([$this->acmeDocument]);
});
