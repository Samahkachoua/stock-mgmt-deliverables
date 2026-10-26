<?php

use App\Services\Stock\ItemCatalog;
use App\Support\CompanyTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stock;

/*
 * No N+1 queries: the number of queries doesn't grow with the number of
 * serials on a line or of items on a form.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->actingAs($this->stock->user);
});

function queryCount(callable $run): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** @return list<string> */
function serialNumbers(string $prefix, int $count): array
{
    return array_map(fn (int $i) => $prefix.$i, range(1, $count));
}

it('receives and sends serials with the same number of queries, however many there are', function () {
    // Warm up both ways (the first delivery note also creates the order's branch).
    $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: ['WARM-1', 'WARM-2'])]);
    $this->stock->post('OUT', [$this->stock->line('PHONE', 1, serials: ['WARM-2'])]);

    $receiveFew = queryCount(fn () => $this->stock->post('IN', [$this->stock->line('PHONE', 2, serials: serialNumbers('A-', 2))]));
    $receiveMany = queryCount(fn () => $this->stock->post('IN', [$this->stock->line('PHONE', 25, serials: serialNumbers('B-', 25))]));
    $sendFew = queryCount(fn () => $this->stock->post('OUT', [$this->stock->line('PHONE', 2, serials: serialNumbers('A-', 2))]));
    $sendMany = queryCount(fn () => $this->stock->post('OUT', [$this->stock->line('PHONE', 25, serials: serialNumbers('B-', 25))]));

    expect($receiveMany)->toBe($receiveFew)
        ->and($sendMany)->toBe($sendFew)
        ->and($this->stock->balance('PHONE'))->toBe(1.0)
        ->and(DB::table('serial_number')->where('status', 'ISSUED')->count())->toBe(28);
});

it('describes the items of a form in one go, with the same stock as one at a time', function () {
    $this->stock->post('IN', [
        $this->stock->line('BEANS', 10),
        $this->stock->line('YOGURT', 6, expiry: '2030-01-31'),
        $this->stock->line('PHONE', 1, serials: ['SN-1']),
    ], date: CompanyTime::today()->subDays(5));
    $this->stock->post('IN', [$this->stock->line('BEANS', 5)]);

    $customer = $this->stock->customer->id;
    $warehouse = $this->stock->warehouse->id;
    $ids = array_map(fn (string $code) => $this->stock->id($code), ['BEANS', 'YOGURT', 'PHONE']);
    $backdated = CompanyTime::today()->subDays(2);

    $one = queryCount(fn () => ItemCatalog::items($customer, [$ids[0]], $warehouse, date: $backdated));
    $all = queryCount(fn () => ItemCatalog::items($customer, $ids, $warehouse, date: $backdated));
    $items = ItemCatalog::items($customer, $ids, $warehouse, date: $backdated);

    expect($all)->toBe($one)
        ->and($items[$ids[0]]['available'])->toBe(10.0)
        ->and($items[$ids[0]])->toBe(ItemCatalog::item($customer, $ids[0], $warehouse, date: $backdated))
        ->and($items[$ids[1]])->toBe(ItemCatalog::item($customer, $ids[1], $warehouse, date: $backdated))
        ->and($items[$ids[2]])->toBe(ItemCatalog::item($customer, $ids[2], $warehouse, date: $backdated));
});
