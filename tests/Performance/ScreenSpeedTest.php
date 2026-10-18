<?php

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\Reports\ReportPage;
use App\Livewire\Transactions\TransactionForm;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Screen speed on the large dataset (database/seeders/PerformanceSeeder.php,
 * docs/performance.md). Not part of the normal suite: run against stock_perf:
 *
 *   DB_DATABASE=stock_perf php artisan test tests/Performance
 *
 * Each screen is opened once to warm up, then three times; the median must
 * stay under the SRS limit (NFR-3: 2 seconds). Times and the slowest query
 * are printed.
 */

const LIMIT_SECONDS = 2.0;

beforeEach(function () {
    if (config('database.connections.pgsql.database') !== 'stock_perf') {
        $this->markTestSkipped('Run with DB_DATABASE=stock_perf after PerformanceSeeder.');
    }

    $this->user = User::where('email', 'admin@example.test')->firstOrFail();
    $this->warehouse = Warehouse::where('code', 'BEY')->firstOrFail();
});

/**
 * Median seconds of three runs (after one warm-up), and the slowest query of the last.
 *
 * @return array{0: float, 1: string}
 */
function timed(callable $run): array
{
    $run();
    $times = [];
    $slowest = ['time' => 0, 'sql' => ''];

    foreach (range(1, 3) as $i) {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['time' => $query->time, 'sql' => $query->sql];
        });
        $start = microtime(true);
        $run();
        $times[] = microtime(true) - $start;
        DB::flushQueryLog();
        $slowest = collect($queries)->sortByDesc('time')->first() ?? $slowest;
    }

    sort($times);

    return [$times[1], sprintf('%d ms: %s', $slowest['time'], mb_substr($slowest['sql'], 0, 160))];
}

function reportSpeed(string $screen, float $seconds, string $slowest): void
{
    fwrite(STDERR, sprintf("\n  %-34s %6.0f ms   slowest query %s", $screen, $seconds * 1000, $slowest));
}

/**
 * A list's remembered filters and page (CLAUDE.md "List state": the session, not the URL).
 *
 * @param  array<string, mixed>  $properties
 * @return array<string, mixed>
 */
function listState(string $key, array $properties, int $page = 1): array
{
    return ['list_state.'.$key => ['properties' => $properties, 'page' => $page]];
}

it('opens every heavy screen in under 2 seconds', function (string $screen, string $route, array $parameters, array $state) {
    $this->actingAs($this->user)->withSession([EnsureCurrentWarehouse::SESSION_KEY => $this->warehouse->id] + $state);
    $url = route($route, $parameters);

    [$seconds, $slowest] = timed(fn () => $this->get($url)->assertOk());
    reportSpeed($screen, $seconds, $slowest);

    expect($seconds)->toBeLessThan(LIMIT_SECONDS);
})->with([
    'Issue list, all dates' => ['Issue list, all dates', 'issue', [], listState('issue', [])],
    'Receive list, page 30' => ['Receive list, page 30', 'receive', [], listState('receive', [], 30)],
    'Damage list' => ['Damage list', 'damage', [], listState('damage', [])],
    'Issue form' => ['Issue form', 'issue.create', [], []],
    'Stock page, this warehouse' => ['Stock page, this warehouse', 'stock', [], []],
    'Stock page, all warehouses' => ['Stock page, all warehouses', 'stock', [], listState('stock', ['filters' => ['warehouse' => 'all']])],
    'Damage report, a year' => ['Damage report, a year', 'reports.show', ['report' => 'damage'], listState('reports.damage', ['filters' => ['from' => '2025-01-01', 'warehouse' => 'all']])],
    'Audit log' => ['Audit log', 'reports.show', ['report' => 'audit-log'], []],
]);

it('opens the stock card and item details of the busiest item in under 2 seconds', function () {
    $this->actingAs($this->user)->withSession([EnsureCurrentWarehouse::SESSION_KEY => $this->warehouse->id]);
    $busiest = DB::table('stock_movement')->where('warehouse_id', $this->warehouse->id)
        ->groupBy('product_id', 'customer_id')->orderByRaw('count(*) desc')->select('product_id', 'customer_id')->first();

    $filters = ['customer' => $busiest->customer_id, 'item' => $busiest->product_id, 'warehouse' => $this->warehouse->id,
        'from' => now()->subYear()->toDateString(), 'to' => now()->toDateString()];
    session(listState('reports.stock-card', ['filters' => $filters]));
    [$seconds, $slowest] = timed(fn () => $this->get(route('reports.show', 'stock-card'))->assertOk()->assertDontSee(__('reports.page.empty_title')));
    reportSpeed('Stock card, busiest item, a year', $seconds, $slowest);
    expect($seconds)->toBeLessThan(LIMIT_SECONDS);

    [$seconds, $slowest] = timed(fn () => $this->get(route('stock.item', [$busiest->product_id, $this->warehouse->id]))->assertOk());
    reportSpeed('Item stock details, busiest item', $seconds, $slowest);
    expect($seconds)->toBeLessThan(LIMIT_SECONDS);

    // The Excel button: the page's export() with its current filters.
    $page = Livewire::actingAs($this->user)->test(ReportPage::class, ['report' => 'stock-card']);
    [$seconds, $slowest] = timed(fn () => expect(data_get($page->call('export', 'xlsx')->effects, 'download'))->not->toBeNull());
    reportSpeed('Stock card Excel export, a year', $seconds, $slowest);
});

it('fills the Issue form quickly: customer, item, availability', function () {
    session([EnsureCurrentWarehouse::SESSION_KEY => $this->warehouse->id]);
    $product = Product::where('sku', 'like', 'PERF-%')->where('track_expiry', true)->firstOrFail();

    [$seconds, $slowest] = timed(function () use ($product) {
        $form = Livewire::actingAs($this->user)->test(TransactionForm::class, ['type' => 'OUT'])->call('chooseCustomer', $product->customer_id);
        [$row] = array_keys($form->get('lines'));
        $form->set("lines.$row.item", $product->id)->set("lines.$row.qty", '2');
    });
    reportSpeed('Issue form: customer + item + qty', $seconds, $slowest);

    expect($seconds)->toBeLessThan(LIMIT_SECONDS);
});
