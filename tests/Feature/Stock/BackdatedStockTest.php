<?php

use App\Livewire\Transactions\TransactionForm;
use App\Models\Warehouse;
use App\Services\Stock\Document;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\SerialProblem;
use App\Services\Stock\PostingService;
use App\Services\Stock\ReceivedLine;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * Stock going out (Transfer, Damage) on a document dated before today
 * can only take what was in the warehouse on that date, and must leave every
 * later day at zero or more. A Delivery note is always dated the day its
 * order is confirmed, and its stock leaves on the day it is delivered.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->today = CarbonImmutable::now('Asia/Beirut')->startOfDay();
    $this->daysAgo = fn (int $days) => $this->today->subDays($days);
});

afterEach(function () {
    expect(Stock::ledgerMismatches())->toBe([]);
});

function documentCount(): int
{
    return DB::table('stock_transaction')->count();
}

it('refuses stock going out dated before the stock arrived, and says what there was on that date', function () {
    // Nothing until yesterday, then 10 received.
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(1));
    $before = documentCount();
    $date = ($this->daysAgo)(21);

    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: $date))
        ->toThrow(InsufficientStock::class, __('stock.errors.not_enough_on_date', [
            'qty' => '0 Piece', 'item' => 'Canned Beans', 'warehouse' => 'Beirut Main', 'date' => Format::date($date),
        ]))
        ->and(documentCount())->toBe($before)
        ->and($this->stock->balance('BEANS'))->toBe(10.0);

    // Dated the day it arrived (or later) is fine.
    expect($this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(1))->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(5.0);
});

it('never lets a later day go below zero', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(20));
    $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 10)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(10));
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(2));

    // 10 were there 15 days ago, but all of them went out 10 days ago.
    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(15)))->toThrow(InsufficientStock::class)
        // Nothing there 5 days ago.
        ->and(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(5)))->toThrow(InsufficientStock::class)
        ->and($this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(1))->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(5.0);
});

it('checks the chosen expiry date on that day', function () {
    $soon = $this->today->addDays(30)->toDateString();
    $late = $this->today->addDays(90)->toDateString();
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $soon)], date: ($this->daysAgo)(10));
    $this->stock->post('IN', [$this->stock->line('YOGURT', 6, expiry: $late)], date: ($this->daysAgo)(1));

    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 2, expiry: $late)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(5)))->toThrow(InsufficientStock::class)
        ->and($this->stock->post('DAMAGE', [$this->stock->line('YOGURT', 2, expiry: $soon)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(5))->status)->toBe('POSTED')
        ->and($this->stock->balance('YOGURT', $late))->toBe(6.0)
        ->and($this->stock->balance('YOGURT', $soon))->toBe(4.0);
});

it('refuses a damage dated before the stock arrived, and posts one dated after', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(1));
    $before = documentCount();

    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 2)], reason: 'HANDLING', date: ($this->daysAgo)(3)))
        ->toThrow(InsufficientStock::class)
        ->and(documentCount())->toBe($before);

    expect($this->stock->post('DAMAGE', [$this->stock->line('BEANS', 2)], reason: 'HANDLING', date: ($this->daysAgo)(1))->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS'))->toBe(8.0);
});

it('checks a damage waiting for approval against its date again when it is approved', function () {
    DB::table('approval_threshold')->insert(['scope' => 'GLOBAL', 'scope_id' => null, 'transaction_type' => 'DAMAGE', 'qty_base' => 5]);
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(10));
    $damage = $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 8)], reason: 'HANDLING', date: ($this->daysAgo)(5));
    // Meanwhile 5 go out dated before the damage: 5 days ago only 5 were left.
    $this->stock->post('DAMAGE', [$this->stock->line('BEANS', 5)], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(8));
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: $this->today);

    expect($damage->status)->toBe('PENDING_APPROVAL')
        ->and(fn () => app(PostingService::class)->approve($damage, userWithRole('MANAGER', $this->stock->warehouse)))->toThrow(InsufficientStock::class)
        ->and($damage->fresh()->status)->toBe('PENDING_APPROVAL')
        ->and($this->stock->balance('BEANS'))->toBe(15.0);
});

it('refuses a serial that arrived after the date, even with enough pieces then', function () {
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['P-1'])], date: ($this->daysAgo)(10));
    $this->stock->post('IN', [$this->stock->line('PHONE', 1, serials: ['P-2'])], date: ($this->daysAgo)(1));

    expect(fn () => $this->stock->post('DAMAGE', [$this->stock->line('PHONE', 1, serials: ['P-2'])], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(5)))
        ->toThrow(SerialProblem::class, __('stock.errors.serial_not_on_date', ['serial' => 'P-2', 'date' => Format::date(($this->daysAgo)(5))]))
        ->and($this->stock->post('DAMAGE', [$this->stock->line('PHONE', 1, serials: ['P-1'])], $this->stock->user, reason: 'HANDLING', date: ($this->daysAgo)(5))->status)->toBe('POSTED');
});

it('counts stock a transfer brings in from the day it was received, not the day it left', function () {
    $tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $user = userWithRole('ADMIN', $this->stock->warehouse, $tripoli);
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(20));

    $transfer = app(PostingService::class)->post(
        new Document('TRANSFER', $this->stock->warehouse->id, $this->stock->customer->id, ($this->daysAgo)(5), null, ['r1' => $this->stock->line('BEANS', 10)], $tripoli->id),
        $user,
    );
    $line = DB::table('stock_transaction_line')->where('transaction_id', $transfer->id)->value('id');
    app(PostingService::class)->receive($transfer, [$line => new ReceivedLine(10)], $user);

    $damage = fn (CarbonImmutable $date) => app(PostingService::class)->post(
        new Document('DAMAGE', $tripoli->id, $this->stock->customer->id, $date, null, ['r1' => $this->stock->line('BEANS', 4)], reason: 'HANDLING'),
        $user,
    );

    expect(fn () => $damage(($this->daysAgo)(3)))->toThrow(InsufficientStock::class)
        ->and($damage($this->today)->status)->toBe('POSTED')
        ->and($this->stock->balance('BEANS', warehouse: $tripoli))->toBe(6.0);
});

it('leaves documents dated today as they were', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: $this->today);

    expect($this->stock->post('OUT', [$this->stock->line('BEANS', 10)])->status)->toBe('POSTED');
});

it('shows on the form what was available on the chosen date', function () {
    $this->stock->post('IN', [$this->stock->line('BEANS', 10)], date: ($this->daysAgo)(1));
    $date = CarbonImmutable::today()->subDays(21);

    $form = Livewire::actingAs($this->stock->user)->test(TransactionForm::class, ['type' => 'DAMAGE'])
        ->call('chooseCustomer', $this->stock->customer->id)
        ->set('date', $date->toDateString());
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $this->stock->id('BEANS'))
        ->set("lines.$row.qty", '5');

    expect($form->html())
        ->toContain(e(__('transactions.form.errors.not_enough_on_date', ['qty' => '0 Piece', 'date' => Format::date($date)])));

    $form->set('date', CarbonImmutable::today()->toDateString());

    expect($form->html())->not->toContain(e(Format::date($date)))
        ->toContain(e(__('transactions.form.available')));
});
