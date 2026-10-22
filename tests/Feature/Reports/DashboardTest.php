<?php

use App\Livewire\Dashboard;
use App\Models\Role;
use App\Models\User;
use App\Reports\Dashboard as Figures;
use App\Reports\Reports;
use App\Support\ListState;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ReportData;
use Tests\Support\Stock;

beforeEach(function () {
    $this->data = ReportData::create();
    startIn($this->data->admin, $this->data->beirut);
    startIn($this->data->beirutManager, $this->data->beirut);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

/**
 * Gives a role the dashboard (only ADMIN has it by default).
 */
function grantDashboard(string $role): void
{
    DB::table('role_permission')->insert([
        'role_id' => Role::where('code', $role)->value('id'),
        'permission_id' => DB::table('permission')->where('code', 'dashboard.view')->value('id'),
    ]);
    Permissions::flush();
}

/**
 * @param  array<string, string>  $filters
 */
function dashboard(User $user, array $filters = []): Testable
{
    session()->forget('list_state');
    $component = Livewire::actingAs($user)->test(Dashboard::class);

    return $filters === [] ? $component : $component->set('filters', array_merge($component->get('filters'), $filters));
}

/**
 * Rows report $key has with the filters the dashboard hands it.
 *
 * @param  array<string, string>  $filters  the dashboard's
 */
function reportRows(string $key, User $user, array $filters, string $status = 'all'): int
{
    $figures = new Figures;

    return Reports::get($key)->rows($figures->reportFilters($key, $figures->normalize($filters, $user), $user, $status), $user)->count();
}

it('opens for ADMIN only, by the dashboard.view permission', function () {
    $this->actingAs($this->data->admin)->get('/dashboard')->assertOk()->assertSee(__('dashboard.title'));
    $this->actingAs($this->data->beirutManager)->get('/dashboard')->assertForbidden();

    // Its sidebar item shows only for whoever may open it.
    $this->actingAs($this->data->admin)->get('/')->assertSee('href="'.route('dashboard').'"', false);
    $this->actingAs($this->data->beirutManager)->get('/')->assertDontSee('href="'.route('dashboard').'"', false);

    grantDashboard('MANAGER');
    $this->actingAs($this->data->beirutManager->fresh())->get('/dashboard')->assertOk();
});

it('counts what is waiting, on its way and expiring, as the reports do', function () {
    $tiles = dashboard($this->data->admin, ['warehouse' => 'all'])->viewData('tiles');

    expect($tiles['pending'])->toBe(['count' => 0, 'oldest_days' => null, 'type' => null])
        // BEANS on its way to Tripoli; the short transfer was received.
        ->and($tiles['transit'])->toBe(['count' => 1, 'late' => 0, 'oldest_days' => 0])
        // YOGURT in 10 days, and the 2 expired ones.
        ->and($tiles['expiry'])->toBe(['count' => 2, 'expired' => 1])
        ->and($tiles['expiry']['count'])->toBe(reportRows('expiry', $this->data->admin, ['warehouse' => 'all']))
        ->and($tiles['damage']['lines'])->toBe(reportRows('damage', $this->data->admin, ['warehouse' => 'all']))
        ->and($tiles['damage']['lines'])->toBeGreaterThan(0)
        ->and($tiles['adjustments']['lines'])->toBe(reportRows('adjustments', $this->data->admin, ['warehouse' => 'all']));
});

it('counts documents waiting for approval and opens the list of that type', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'scope_id' => null, 'transaction_type' => 'DAMAGE', 'qty_base' => 0]);
    $this->data->stock->post('DAMAGE', [$this->data->stock->line('BEANS', 1)], reason: 'HANDLING');

    $component = dashboard($this->data->admin, ['warehouse' => 'all']);

    expect($component->viewData('tiles')['pending'])->toBe(['count' => 1, 'oldest_days' => 0, 'type' => 'DAMAGE']);

    $component->call('open', 'pending')->assertRedirect(route('damage'));
    expect(ListState::get('damage')['properties'])->toMatchArray(['status' => 'PENDING_APPROVAL', 'warehouse' => 'all']);
});

it('only counts the warehouses and customer chosen', function () {
    grantDashboard('MANAGER');
    $manager = $this->data->beirutManager->fresh();

    // Beirut only: Tripoli's damage and documents are not there, even with "All my warehouses".
    $component = dashboard($manager, ['warehouse' => 'all']);
    expect($component->get('filters.warehouse'))->toBe('all')
        ->and(collect($component->viewData('warehouses'))->pluck('name')->all())->toBe(['Beirut Main'])
        ->and($component->viewData('tiles')['damage']['lines'])->toBe(reportRows('damage', $manager, ['warehouse' => 'all']))
        ->and($component->viewData('tiles')['transit']['count'])->toBe(1);

    // A warehouse the user doesn't have falls back to their default.
    expect(dashboard($manager, ['warehouse' => $this->data->tripoli->id])->get('filters.warehouse'))->toBe($this->data->beirut->id);

    // Cedar only: no expiring stock, its one damage line in Tripoli.
    $cedar = dashboard($this->data->admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id])->viewData('tiles');
    expect($cedar['expiry'])->toBe(['count' => 0, 'expired' => 0])
        ->and($cedar['damage']['lines'])->toBe(1)
        ->and($cedar['transit']['count'])->toBe(0);
});

it('charts completed documents per type and lines per warehouse over the dates', function () {
    $today = now()->toDateString();
    $component = dashboard($this->data->admin, ['warehouse' => 'all', 'from' => $today, 'to' => $today]);
    $activity = $component->viewData('activity');

    $expected = DB::table('stock_transaction')->whereIn('status', ['POSTED', 'DISPATCHED'])
        ->groupBy('transaction_type')->selectRaw('transaction_type, count(*) as n')->pluck('n', 'transaction_type')->map(fn ($n) => (int) $n);

    expect($activity['step'])->toBe('day')
        ->and($activity['buckets'])->toBe([$today])
        ->and(collect($activity['series'])->map(fn ($series) => $series['total'])->filter()->sortKeys()->all())->toBe($expected->sortKeys()->all())
        ->and(array_sum(array_column($component->viewData('warehouses'), 'lines')))
        ->toBe(DB::table('stock_transaction_line as l')->join('stock_transaction as t', 't.id', '=', 'l.transaction_id')->whereIn('t.status', ['POSTED', 'DISPATCHED'])->count());

    // Every type shares one scale: the busiest bar is full height, none is taller.
    preg_match_all('/style="height: (\d+)%"/', $component->html(), $heights);
    expect(array_map('intval', $heights[1]))->not->toBeEmpty()->each->toBeLessThanOrEqual(100)
        ->and(max(array_map('intval', $heights[1])))->toBe(100);

    // Days with nothing: empty charts, nothing counted.
    $quiet = dashboard($this->data->admin, ['warehouse' => 'all', 'from' => now()->subDays(20)->toDateString(), 'to' => now()->subDays(10)->toDateString()]);
    expect(collect($quiet->viewData('activity')['series'])->sum('total'))->toBe(0)
        ->and($quiet->viewData('activity')['buckets'])->toHaveCount(11)
        ->and($quiet->viewData('tiles')['damage']['lines'])->toBe(0);
    $quiet->assertSee(__('dashboard.activity.empty'));

    // Months once the dates span more than a month.
    expect(dashboard($this->data->admin, ['from' => now()->subMonths(5)->toDateString(), 'to' => $today])->viewData('activity')['step'])->toBe('month');
});

it('shows dates the wrong way round instead of counting', function () {
    dashboard($this->data->admin, ['from' => now()->toDateString(), 'to' => now()->subDay()->toDateString()])
        ->assertSee(__('feedback.date_order'))
        ->assertViewHas('warehouses', []);
});

it('lists stock that has not moved, and opens the reports with the same filters', function () {
    expect(dashboard($this->data->admin, ['warehouse' => 'all'])->viewData('dormant')['total'])->toBe(0);

    $this->travel(100)->days();

    $component = dashboard($this->data->admin, ['warehouse' => 'all']);
    $dormant = $component->viewData('dormant');
    $inStock = DB::table('stock_balance')->where('qty_base', '>', 0)->distinct()->count(DB::raw('(warehouse_id, customer_id, product_id)'));

    expect($dormant['total'])->toBe($inStock)
        ->and($dormant['rows'])->toHaveCount(min($inStock, Figures::TOP_ROWS))
        ->and($dormant['days'])->toBe(90)
        ->and($dormant['total'])->toBe(reportRows('dormant-stock', $this->data->admin, ['warehouse' => 'all']));

    $component->call('open', 'dormant-stock')->assertRedirect(route('reports.show', 'dormant-stock'));
    expect(ListState::get('reports.dormant-stock')['properties']['filters'])->toMatchArray(['warehouse' => 'all', 'customer' => '', 'days' => '90']);

    $component->call('open', 'expiry');
    expect(ListState::get('reports.expiry')['properties']['filters'])->toMatchArray(['warehouse' => 'all', 'status' => 'all', 'days' => '30']);
});

it('keeps the dormant stock report to the user\'s warehouses', function () {
    $this->travel(100)->days();
    $report = Reports::get('dormant-stock');
    $manager = $this->data->beirutManager;

    $rows = $report->rows($report->normalize(['warehouse' => 'all'], $manager), $manager);

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('warehouse')->unique()->values()->all())->toBe(['Beirut Main'])
        ->and($report->count($report->normalize(['warehouse' => 'all'], $manager), $manager))->toBe($rows->count());

    // The Tripoli operator sees only Tripoli's.
    $operator = $this->data->tripoliOperator;
    expect($report->rows($report->normalize(['warehouse' => 'all'], $operator), $operator)->pluck('warehouse')->unique()->values()->all())->toBe(['Tripoli']);
});

it('renders in Arabic', function () {
    app()->setLocale('ar');

    dashboard($this->data->admin, ['warehouse' => 'all'])
        ->assertSee(__('dashboard.activity.title'))
        ->assertSee(__('dashboard.tiles.pending'));
});
