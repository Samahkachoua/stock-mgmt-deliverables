<?php

use App\Models\Scopes\WarehouseScope;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\ScopedRecord;
use Tests\Fixtures\ScopedTransfer;

beforeEach(function () {
    // Temporary, so it lives only inside the test's transaction and never in `public`.
    DB::statement('CREATE TEMPORARY TABLE scoped_record (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, warehouse_id BIGINT NOT NULL, to_warehouse_id BIGINT)');

    $this->mine = Warehouse::factory()->create();
    $this->other = Warehouse::factory()->create();
    $this->user = userWithWarehouses($this->mine);
});

it('shows only rows in the user\'s warehouses', function () {
    $visible = ScopedRecord::create(['warehouse_id' => $this->mine->id]);
    ScopedRecord::create(['warehouse_id' => $this->other->id]);

    $this->actingAs($this->user);

    expect(ScopedRecord::pluck('id')->all())->toBe([$visible->id])
        ->and(ScopedRecord::find(ScopedRecord::withoutGlobalScope(WarehouseScope::class)
            ->where('warehouse_id', $this->other->id)->value('id')))->toBeNull();
});

it('hides rows of a warehouse that was deactivated', function () {
    ScopedRecord::create(['warehouse_id' => $this->mine->id]);
    $this->mine->update(['active' => false]);

    $this->actingAs($this->user->fresh());

    expect(ScopedRecord::count())->toBe(0);
});

it('shows a transfer in both its source and destination warehouse', function () {
    $incoming = ScopedTransfer::create(['warehouse_id' => $this->other->id, 'to_warehouse_id' => $this->mine->id]);
    $outgoing = ScopedTransfer::create(['warehouse_id' => $this->mine->id, 'to_warehouse_id' => $this->other->id]);
    $third = Warehouse::factory()->create();
    ScopedTransfer::create(['warehouse_id' => $this->other->id, 'to_warehouse_id' => $third->id]);

    $this->actingAs($this->user);

    expect(ScopedTransfer::pluck('id')->all())->toEqualCanonicalizing([$incoming->id, $outgoing->id]);
});

it('shows nothing to a user without warehouses', function () {
    ScopedRecord::create(['warehouse_id' => $this->mine->id]);
    $this->user->warehouses()->detach();

    $this->actingAs($this->user->fresh());

    expect(ScopedRecord::count())->toBe(0);
});

it('does not filter without a signed-in user', function () {
    ScopedRecord::create(['warehouse_id' => $this->mine->id]);
    ScopedRecord::create(['warehouse_id' => $this->other->id]);

    expect(ScopedRecord::count())->toBe(2);
});

it('checks warehouse access through the warehouse.access gate', function () {
    expect(Gate::forUser($this->user)->allows('warehouse.access', $this->mine))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('warehouse.access', $this->mine->id))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('warehouse.access', $this->other))->toBeFalse();
});
