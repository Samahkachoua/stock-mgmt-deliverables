<?php

use App\Livewire\Dashboard;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\User;
use App\Reports\Dashboard as Figures;
use App\Reports\Reports;
use App\Services\Stock\DeliveredLine;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\PostingService;
use App\Support\ListState;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
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

it('has no key figure boxes', function () {
    dashboard($this->data->admin, ['warehouse' => 'all'])
        ->assertViewMissing('tiles')
        ->assertDontSeeHtml('data-tile');
});

it('only counts the warehouses and customer chosen', function () {
    grantDashboard('MANAGER');
    $manager = $this->data->beirutManager->fresh();

    // Beirut only: Tripoli's documents are not there, even with "All my warehouses".
    $component = dashboard($manager, ['warehouse' => 'all']);
    expect($component->get('filters.warehouse'))->toBe('all')
        ->and(collect($component->viewData('warehouses'))->pluck('name')->all())->toBe(['Beirut Main'])
        ->and($component->viewData('expiring')['total'])->toBe(reportRows('expiry', $manager, ['warehouse' => 'all']));

    // A warehouse the user doesn't have falls back to their default.
    expect(dashboard($manager, ['warehouse' => $this->data->tripoli->id])->get('filters.warehouse'))->toBe($this->data->beirut->id);

    // Cedar only: no expiring stock; only Cedar's work.
    $cedar = dashboard($this->data->admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id]);
    expect($cedar->viewData('expiring')['total'])->toBe(0)
        ->and(array_column($cedar->viewData('customers'), 'customer'))->toBe(['Cedar Trading']);
});

it('charts lines per warehouse over the dates', function () {
    $today = now()->toDateString();
    $component = dashboard($this->data->admin, ['warehouse' => 'all', 'from' => $today, 'to' => $today]);

    expect(array_sum(array_column($component->viewData('warehouses'), 'lines')))
        ->toBe(DB::table('stock_transaction_line as l')->join('stock_transaction as t', 't.id', '=', 'l.transaction_id')->whereIn('t.status', ['POSTED', 'DISPATCHED'])->count());

    // Days with nothing: nothing counted.
    $quiet = dashboard($this->data->admin, ['warehouse' => 'all', 'from' => now()->subDays(20)->toDateString(), 'to' => now()->subDays(10)->toDateString()]);
    expect(array_sum(array_column($quiet->viewData('warehouses'), 'lines')))->toBe(0)
        ->and($quiet->viewData('customers'))->toBe([]);
});

/**
 * Orders in three branches: Acme "Main branch" (2 open, one of them late;
 * 1 ready to dispatch; 1 delivered; 1 cancelled), Acme "Airport" (1 on the
 * way) and Cedar "Downtown" (1 open). ReportData has already delivered one
 * Main branch order (its Delivery note).
 *
 * @return array{main: CustomerBranch, airport: CustomerBranch, downtown: CustomerBranch}
 */
function branchOrders(ReportData $data): array
{
    $stock = $data->stock;
    $beans = fn () => [$stock->line('BEANS', 1)];

    $stock->order($beans());
    $late = $stock->order($beans());
    DB::table('stock_draft')->where('id', $late->id)->update(['document_date' => now()->subDays(3)->toDateString(), 'delivery_date' => now()->subDays(2)->toDateString()]);
    $stock->confirmOrder($beans());
    $stock->deliver($beans());
    app(DraftService::class)->cancel($stock->order($beans()), $stock->user, 'Not needed');

    $main = CustomerBranch::where('customer_id', $data->acme->id)->where('code', 'MAIN')->firstOrFail();
    $airport = CustomerBranch::create(['customer_id' => $data->acme->id, 'code' => 'AIR', 'name' => 'Airport']);
    $onWay = $stock->confirmOrder($beans());
    app(PostingService::class)->dispatchDelivery($onWay, driver()->id, $stock->user);
    DB::table('stock_draft')->where('id', $onWay->draft_id)->update(['customer_branch_id' => $airport->id]);
    DB::table('stock_transaction')->where('id', $onWay->id)->update(['customer_branch_id' => $airport->id]);

    $downtown = CustomerBranch::create(['customer_id' => $data->cedar->id, 'code' => 'DT', 'name' => 'Downtown']);
    $today = CarbonImmutable::today();
    app(DraftService::class)->save(new DraftInput(
        'OUT', $data->cedar->id, $downtown->id, $data->beirut->id, $today, null,
        ['r1' => new DraftLineInput($data->rice->id, 'PC', '1', null, [], null)],
        deliveryDate: $today, priority: 'NORMAL',
    ), $stock->user);

    return compact('main', 'airport', 'downtown');
}

it('shows orders per customer branch, by stage, busiest first', function () {
    $branches = branchOrders($this->data);

    $rows = dashboard($this->data->admin, ['warehouse' => 'all'])->viewData('branches');

    expect(array_column($rows, 'branch'))->toBe(['Main branch', 'Airport', 'Downtown'])
        ->and($rows[0])->toMatchArray([
            'branch_id' => $branches['main']->id, 'customer' => 'Acme Foods',
            'stages' => ['open' => 2, 'ready' => 1, 'on_way' => 0, 'delivered' => 2], 'total' => 5, 'late' => 1,
        ])
        ->and($rows[1]['stages'])->toBe(['open' => 0, 'ready' => 0, 'on_way' => 1, 'delivered' => 0])
        ->and($rows[2])->toMatchArray(['customer' => 'Cedar Trading', 'total' => 1, 'late' => 0]);

    // One customer: only its branches. Another warehouse: none.
    expect(array_column(dashboard($this->data->admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id])->viewData('branches'), 'branch'))->toBe(['Downtown'])
        ->and(dashboard($this->data->admin, ['warehouse' => $this->data->tripoli->id])->viewData('branches'))->toBe([]);

    // The dates count the order's own date: the late one is from three days ago.
    $today = now()->toDateString();
    $main = dashboard($this->data->admin, ['warehouse' => 'all', 'from' => $today, 'to' => $today])->viewData('branches')[0];
    expect($main['total'])->toBe(4)->and($main['late'])->toBe(0);

    // Each customer stands alone: its own block (A–Z) and its own scale, so
    // Cedar's one order is full width, not compared with Acme's busiest branch.
    $html = dashboard($this->data->admin, ['warehouse' => 'all'])->html();
    preg_match_all('/style="width: (\d+)%" data-bar/', $html, $widths);
    preg_match_all('/data-customer-group="(\d+)"/', $html, $groups);
    expect(array_map('intval', $widths[1]))->toBe([100, 20, 100])
        ->and(array_slice($groups[1], 0, 2))->toBe([$this->data->acme->id, $this->data->cedar->id]);

    // Within each customer, busiest branch first; customers A–Z, whatever their size.
    $other = Customer::factory()->create(['code' => 'AAA', 'name' => 'Aardvark Co']);
    $branch = CustomerBranch::create(['customer_id' => $other->id, 'code' => 'ONE', 'name' => 'Only branch']);
    DB::table('stock_draft')->where('id', DB::table('stock_draft')->where('customer_id', $this->data->cedar->id)->value('id'))
        ->update(['customer_id' => $other->id, 'customer_branch_id' => $branch->id]);
    expect(array_column(dashboard($this->data->admin, ['warehouse' => 'all'])->viewData('branches'), 'customer'))
        ->toBe(['Aardvark Co', 'Acme Foods', 'Acme Foods']);
});

it('opens the orders of a branch with the dashboard filters, and only for users who may see orders', function () {
    $branches = branchOrders($this->data);
    $from = now()->subWeek()->toDateString();

    dashboard($this->data->admin, ['warehouse' => 'all', 'from' => $from])
        ->call('open', 'orders', $branches['airport']->id)
        ->assertRedirect(route('orders'));
    expect(ListState::get('orders')['properties'])->toMatchArray([
        'customer' => $this->data->acme->id, 'branch' => $branches['airport']->id, 'dateFrom' => $from, 'dateTo' => '',
    ]);

    // A branch the dashboard doesn't count is refused.
    dashboard($this->data->admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id])
        ->call('open', 'orders', $branches['airport']->id)
        ->assertNotFound();

    // Without the orders list: no card, and the row can't be opened.
    grantDashboard('AUDITOR');
    $auditor = $this->data->auditorTripoli->fresh();
    dashboard($auditor, ['warehouse' => 'all'])
        ->assertViewHas('branches', null)
        ->assertDontSee(__('dashboard.branches.title'))
        ->call('open', 'orders', $branches['main']->id)
        ->assertForbidden();
});

it('shows a customer user only the branches they work for', function () {
    $branches = branchOrders($this->data);
    grantDashboard('AUDITOR');
    DB::table('role_permission')->insert([
        'role_id' => Role::where('code', 'AUDITOR')->value('id'),
        'permission_id' => DB::table('permission')->where('code', 'draft.out.view')->value('id'),
    ]);
    Permissions::flush();
    DB::table('user_customer_branch')->insert(['user_id' => $this->data->acmePortal->id, 'branch_id' => $branches['airport']->id]);

    expect(array_column(dashboard($this->data->acmePortal->fresh(), ['warehouse' => 'all'])->viewData('branches'), 'branch'))->toBe(['Airport']);
});

/**
 * Delivery notes delivered short in two Acme branches: "Main branch" (one
 * line not available, one refused; plus ReportData's note delivered in full)
 * and "Airport" (one damaged); "Quiet" got everything it ordered.
 *
 * @return array{main: CustomerBranch, airport: CustomerBranch, quiet: CustomerBranch}
 */
function shortDeliveries(ReportData $data): array
{
    $stock = $data->stock;
    $posting = app(PostingService::class);
    $deliver = function (StockTransaction $note, array $delivered) use ($posting, $stock) {
        $posting->dispatchDelivery($note, driver()->id, $stock->user);
        $lines = DB::table('stock_transaction_line')->where('transaction_id', $note->id)->orderBy('line_no')->pluck('id')->all();
        $posting->deliver($note, array_combine(array_map('strval', $lines), $delivered), $stock->user);
    };
    $move = function (StockTransaction $note, CustomerBranch $branch) {
        DB::table('stock_draft')->where('id', $note->draft_id)->update(['customer_branch_id' => $branch->id]);
        DB::table('stock_transaction')->where('id', $note->id)->update(['customer_branch_id' => $branch->id]);
    };

    $main = $stock->confirmOrder([$stock->line('BEANS', 2), $stock->line('BEANS', 1, 'BOX')]);
    $deliver($main, [new DeliveredLine('1', 'NOT_AVAILABLE'), new DeliveredLine('0', 'REFUSED')]);

    $airport = CustomerBranch::create(['customer_id' => $data->acme->id, 'code' => 'AIR', 'name' => 'Airport']);
    $note = $stock->confirmOrder([$stock->line('BEANS', 1)]);
    $move($note, $airport);
    $deliver($note, [new DeliveredLine('0', 'DAMAGED')]);

    $quiet = CustomerBranch::create(['customer_id' => $data->acme->id, 'code' => 'QT', 'name' => 'Quiet']);
    $move($stock->deliver([$stock->line('BEANS', 1)]), $quiet);

    return [
        'main' => CustomerBranch::where('customer_id', $data->acme->id)->where('code', 'MAIN')->firstOrFail(),
        'airport' => $airport,
        'quiet' => $quiet,
    ];
}

/**
 * Rows of the Delivery discrepancies report with these filters.
 *
 * @param  array<string, string>  $filters
 */
function discrepancyRows(User $user, array $filters): int
{
    $report = Reports::get('delivery-differences');

    return $report->rows($report->normalize($filters, $user), $user)->count();
}

it('shows delivery discrepancies per branch, by reason, as the report counts them', function () {
    $branches = shortDeliveries($this->data);
    $admin = $this->data->admin;

    $rows = dashboard($admin, ['warehouse' => 'all'])->viewData('discrepancies');

    // Main: 2 of its 3 delivered lines short; Airport: its only line. Quiet got everything: not listed.
    expect(array_column($rows, 'branch'))->toBe(['Main branch', 'Airport'])
        ->and($rows[0])->toMatchArray([
            'branch_id' => $branches['main']->id, 'customer' => 'Acme Foods',
            'reasons' => ['NOT_AVAILABLE' => 1, 'REFUSED' => 1, 'DAMAGED' => 0, 'REPLACED' => 0],
            'short' => 2, 'delivered' => 3, 'rate' => 67,
        ])
        ->and($rows[1])->toMatchArray(['short' => 1, 'delivered' => 1, 'rate' => 100]);

    // Each branch's count is the report's rows for that branch.
    foreach ($rows as $row) {
        expect($row['short'])->toBe(discrepancyRows($admin, ['warehouse' => 'all', 'customer' => $row['customer_id'], 'branch' => $row['branch_id']]));
    }

    // Another customer, another warehouse, or days before any delivery: nothing.
    $tomorrow = now()->addDay()->toDateString();
    expect(dashboard($admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id])->viewData('discrepancies'))->toBe([])
        ->and(dashboard($admin, ['warehouse' => $this->data->tripoli->id])->viewData('discrepancies'))->toBe([])
        ->and(dashboard($admin, ['warehouse' => 'all', 'from' => $tomorrow])->viewData('discrepancies'))->toBe([]);

    // One scale: the branch with the most short lines is full width.
    preg_match_all('/style="width: (\d+)%" data-short-bar/', dashboard($admin, ['warehouse' => 'all'])->html(), $widths);
    expect(array_map('intval', $widths[1]))->toBe([100, 50]);
});

it('opens the delivery discrepancies report on a branch, only for users who may see it', function () {
    $branches = shortDeliveries($this->data);

    dashboard($this->data->admin, ['warehouse' => 'all'])
        ->call('open', 'delivery-differences', $branches['airport']->id)
        ->assertRedirect(route('reports.show', 'delivery-differences'));
    expect(ListState::get('reports.delivery-differences')['properties']['filters'])
        ->toMatchArray(['warehouse' => 'all', 'customer' => $this->data->acme->id, 'branch' => $branches['airport']->id]);

    // A branch the dashboard doesn't count is refused.
    dashboard($this->data->admin, ['warehouse' => 'all'])
        ->call('open', 'delivery-differences', $branches['quiet']->id)
        ->assertNotFound();

    // Without the report: no card.
    grantDashboard('AUDITOR');
    DB::table('role_permission')
        ->where('role_id', Role::where('code', 'AUDITOR')->value('id'))
        ->where('permission_id', DB::table('permission')->where('code', Reports::get('delivery-differences')->permission())->value('id'))
        ->delete();
    Permissions::flush();
    dashboard($this->data->auditorTripoli->fresh(), ['warehouse' => 'all'])
        ->assertViewHas('discrepancies', null)
        ->assertDontSee(__('dashboard.discrepancies.title'));
});

it('keeps a customer user to their branches, on the dashboard and in the report', function () {
    $branches = shortDeliveries($this->data);
    grantDashboard('AUDITOR');
    DB::table('role_permission')->insertOrIgnore([
        'role_id' => Role::where('code', 'AUDITOR')->value('id'),
        'permission_id' => DB::table('permission')->where('code', Reports::get('delivery-differences')->permission())->value('id'),
    ]);
    Permissions::flush();
    DB::table('user_customer_branch')->insert(['user_id' => $this->data->acmePortal->id, 'branch_id' => $branches['airport']->id]);
    $portal = $this->data->acmePortal->fresh();

    expect(array_column(dashboard($portal, ['warehouse' => 'all'])->viewData('discrepancies'), 'branch'))->toBe(['Airport'])
        ->and(discrepancyRows($portal, ['warehouse' => 'all']))->toBe(1);
});

it('filters the delivery discrepancies report by a branch of the chosen customer', function () {
    $branches = shortDeliveries($this->data);
    $admin = $this->data->admin;
    $report = Reports::get('delivery-differences');
    $acme = $this->data->acme->id;

    expect(discrepancyRows($admin, ['warehouse' => 'all']))->toBe(3)
        ->and(discrepancyRows($admin, ['warehouse' => 'all', 'customer' => $acme, 'branch' => $branches['main']->id]))->toBe(2)
        // No customer, or another customer's: the branch is dropped.
        ->and($report->normalize(['customer' => '', 'branch' => $branches['main']->id], $admin)['branch'])->toBe('')
        ->and($report->normalize(['customer' => $this->data->cedar->id, 'branch' => $branches['main']->id], $admin)['branch'])->toBe('');

    $choices = collect($report->filters())->firstWhere('name', 'branch')->choices($admin, ['customer' => $acme]);
    expect(array_values($choices))->toBe([__('reports.filters.all_branches'), 'Airport', 'Main branch', 'Quiet']);
});

it('shows the work done per customer, by kind, adding up to the work per warehouse', function () {
    $admin = $this->data->admin;
    $component = dashboard($admin, ['warehouse' => 'all']);
    $customers = $component->viewData('customers');

    // Lines of completed documents per customer and kind, straight from the database.
    $expected = DB::table('stock_transaction_line as l')
        ->join('stock_transaction as t', 't.id', '=', 'l.transaction_id')
        ->join('transaction_type as tt', 'tt.code', '=', 't.transaction_type')
        ->join('transaction_status as s', 's.code', '=', 't.status')
        ->where('s.affects_stock', true)
        ->groupBy('t.customer_id')
        ->selectRaw("t.customer_id,
            count(*) FILTER (WHERE tt.posting_direction = 'IN' AND NOT tt.requires_dispatch_receive) as g_in,
            count(*) FILTER (WHERE tt.posting_direction = 'OUT' AND NOT tt.requires_dispatch_receive) as g_out,
            count(*) FILTER (WHERE tt.requires_dispatch_receive) as transfers,
            count(*) FILTER (WHERE tt.posting_direction IS NULL AND NOT tt.requires_dispatch_receive) as counts")
        ->get()
        ->mapWithKeys(fn ($row) => [(string) $row->customer_id => ['in' => (int) $row->g_in, 'out' => (int) $row->g_out, 'transfers' => (int) $row->transfers, 'counts' => (int) $row->counts]]);

    expect(collect($customers)->mapWithKeys(fn ($row) => [$row['customer_id'] => $row['groups']])->sortKeys()->all())->toBe($expected->sortKeys()->all())
        ->and(array_sum(array_column($customers, 'lines')))->toBe(array_sum(array_column($component->viewData('warehouses'), 'lines')))
        ->and(array_column($customers, 'customer'))->toBe(['Acme Foods', 'Cedar Trading']);

    // Cedar's count and transfer are under their own kinds.
    $cedar = collect($customers)->firstWhere('customer_id', $this->data->cedar->id);
    expect($cedar['groups']['counts'])->toBe(1)->and($cedar['groups']['transfers'])->toBe(1);

    // Each customer stands alone: no share of the others' work, no shared scale.
    $html = $component->html();
    expect($html)->not->toContain('data-customer-bar style')
        ->and(substr_count($html, 'data-customer-bar'))->toBe(2)
        ->and($customers[0])->not->toHaveKey('share');

    // One customer chosen: only it, the same figures.
    $only = dashboard($admin, ['warehouse' => 'all', 'customer' => $this->data->cedar->id])->viewData('customers');
    expect($only)->toBe([$cedar]);

    // Tripoli only: Acme did nothing there (its transfer counts at Beirut, where it was sent from).
    expect(array_column(dashboard($admin, ['warehouse' => $this->data->tripoli->id])->viewData('customers'), 'customer'))->toBe(['Cedar Trading']);
});

it('narrows the whole dashboard to a customer chosen on the workload card', function () {
    branchOrders($this->data);

    $component = dashboard($this->data->admin, ['warehouse' => 'all'])
        ->call('showCustomer', $this->data->cedar->id);

    expect($component->get('filters.customer'))->toBe($this->data->cedar->id)
        ->and(array_column($component->viewData('branches'), 'customer'))->toBe(['Cedar Trading'])
        ->and(ListState::get('dashboard')['properties']['filters']['customer'])->toBe($this->data->cedar->id);

    // A customer the card doesn't show is refused.
    $quiet = Customer::factory()->create(['code' => 'QUIET', 'name' => 'Quiet Co']);
    dashboard($this->data->admin, ['warehouse' => 'all'])->call('showCustomer', $quiet->id)->assertNotFound();
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
        ->assertSee(__('dashboard.branches.title'))
        ->assertSee(__('dashboard.customers.title'));
});
