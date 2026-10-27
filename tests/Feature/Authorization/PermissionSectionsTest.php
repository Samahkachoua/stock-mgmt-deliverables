<?php

use App\Livewire\Settings\Roles;
use App\Livewire\Transactions\CancelTransaction;
use App\Models\Permission;
use App\Models\Warehouse;
use App\Reports\Reports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Stock;

it('gives every report on the Reports page its own permission', function () {
    // Stock on hand is the Stock page itself (stock.view).
    $codes = collect(Reports::all())->except('stock-on-hand')->map(fn ($report) => $report->permission());

    expect($codes->unique()->count())->toBe($codes->count());

    foreach ($codes as $code) {
        expect(DB::table('permission')->where('code', $code)->value('module'))->toBe('REPORT');
    }
});

it('lists and opens only the reports the user may see', function () {
    $user = userWithPermissions(Warehouse::factory()->create(), ['report.expiry.view']);

    expect(array_keys(Reports::listedFor($user)))->toBe(['expiry'])
        ->and(Gate::forUser($user)->allows('reports.open'))->toBeTrue();

    $this->actingAs($user)->get(route('reports'))->assertOk()
        ->assertSee(Reports::get('expiry')->title())
        ->assertDontSee(Reports::get('damage')->description());
    $this->actingAs($user)->get(route('reports.show', 'expiry'))->assertOk();
    $this->actingAs($user)->get(route('reports.show', 'damage'))->assertForbidden();
});

it('closes the Reports page to users without any report', function () {
    $user = userWithPermissions(Warehouse::factory()->create(), ['stock.view']);

    $this->actingAs($user)->get(route('reports'))->assertForbidden();
});

it('cancels a type only with that type\'s cancel permission', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 3)]);
    $opening = $stock->post('OPENING_BALANCE', [$stock->line('BEANS', 2)]);
    $user = userWithPermissions($stock->warehouse, ['stock.in.view', 'stock.opening.view', 'stock.opening.cancel']);

    Livewire::actingAs($user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->assertForbidden();

    Livewire::actingAs($user)->test(CancelTransaction::class, ['transactionId' => $opening->id])
        ->call('ask')
        ->assertOk();
});

it('shows Stock, then one section per document type, then the rest', function () {
    $modules = Livewire::actingAs(userWithRole('ADMIN'))->test(Roles::class)
        ->instance()->modules;

    expect($modules->keys()->all())->toBe(Permission::MODULES)
        ->and($modules['STOCK']->pluck('code')->all())->toBe(['stock.view'])
        ->and($modules['IN']->pluck('code')->all())->toBe(['stock.in.view', 'stock.in.create', 'stock.in.cancel'])
        ->and($modules['DRAFT_OUT']->pluck('code')->all())->toBe(['draft.out.view', 'draft.out.create', 'draft.out.confirm', 'draft.out.cancel'])
        ->and($modules['REPORT']->pluck('code')->all())->toContain('audit.view', 'dashboard.view', 'report.stock_card.view');
});

it('names each report permission after its report', function (string $locale) {
    app()->setLocale($locale);
    $permission = Permission::where('code', 'report.stock_card.view')->firstOrFail();

    expect($permission->label())->toBe(__('permissions.report', ['report' => Reports::get('stock-card')->title()]))
        ->and(Permission::where('code', 'stock.view')->firstOrFail()->label())->toBe(__('permissions.labels.stock_view'));
})->with(['en', 'ar', 'fr']);

it('converts an older database without anyone losing access', function () {
    $migration = require database_path('migrations/2026_11_05_000000_split_permissions_by_type_and_report.php');
    $custom = DB::table('role')->insertGetId(['code' => 'CUSTOM', 'name' => 'Custom'], 'id');
    DB::table('role_permission')->insert(['role_id' => $custom, 'permission_id' => DB::table('permission')->where('code', 'report.damage.view')->value('id')]);

    $migration->down();
    $holders = fn (string $code) => DB::table('role_permission as rp')->join('permission as p', 'p.id', '=', 'rp.permission_id')
        ->join('role as r', 'r.id', '=', 'rp.role_id')->where('p.code', $code)->orderBy('r.code')->pluck('r.code')->all();

    expect($holders('report.view'))->toBe(['ADMIN', 'AUDITOR', 'CUSTOM', 'MANAGER'])
        ->and($holders('transaction.cancel'))->toBe(['ADMIN', 'AUDITOR', 'MANAGER']);

    $migration->up();

    expect($holders('report.expiry.view'))->toBe(['ADMIN', 'AUDITOR', 'CUSTOM', 'MANAGER'])
        ->and($holders('stock.out.cancel'))->toBe(['ADMIN', 'AUDITOR', 'MANAGER'])
        ->and(DB::table('permission')->whereIn('code', ['report.view', 'transaction.cancel'])->exists())->toBeFalse()
        ->and(DB::table('permission')->where('code', 'stock.in.create')->value('module'))->toBe('IN');
});
