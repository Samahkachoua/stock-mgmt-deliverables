<?php

use App\Support\Home\RecentActivity;
use Illuminate\Support\Facades\DB;
use Tests\Support\Stock;

/*
 * Home's "Pick up where you left off": the documents and drafts the user last
 * worked on, limited to what they may still open.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->receipt = $this->stock->post('IN', [$this->stock->line('BEANS', 50)]);
    $this->order = $this->stock->order([$this->stock->line('BEANS', 5)]);
    $this->actingAs($this->stock->user);
});

it('lists the user’s documents and drafts newest first, once each, with their last action', function () {
    $rows = RecentActivity::forUser($this->stock->user);

    expect(array_column($rows, 'number'))->toBe([$this->order->doc_no, $this->receipt->doc_no])
        ->and($rows[0]['event'])->toBe('created')
        ->and($rows[0]['draft'])->toBeTrue()
        ->and($rows[0]['url'])->toBe(route('orders.show', $this->order->doc_no))
        ->and($rows[1]['event'])->toBe('posted')
        ->and($rows[1]['status'])->toBe('POSTED')
        ->and($rows[1]['url'])->toBe(route('receive.show', $this->receipt->doc_no));
});

it('moves a record back to the top when the user works on it again', function () {
    $note = $this->stock->post('OUT', [$this->stock->line('BEANS', 1)]);
    DB::table('audit_log')->insert(['entity' => 'stock_transaction', 'entity_id' => $this->receipt->id, 'action' => 'CANCEL', 'user_id' => $this->stock->user->id]);

    $rows = RecentActivity::forUser($this->stock->user);

    expect($rows[0]['number'])->toBe($this->receipt->doc_no)
        ->and($rows[0]['event'])->toBe('cancelled')
        ->and(array_column($rows, 'number'))->toContain($note->doc_no)
        ->and(array_count_values(array_column($rows, 'number'))[$this->receipt->doc_no])->toBe(1);
});

it('shows nothing another user did', function () {
    $other = userWithRole('ADMIN', $this->stock->warehouse);

    expect(RecentActivity::forUser($other))->toBe([]);
});

it('leaves out types the user may not open', function () {
    $user = userWithPermissions($this->stock->warehouse, ['stock.out.view']);
    DB::table('audit_log')->where('user_id', $this->stock->user->id)->update(['user_id' => $user->id]);
    $this->actingAs($user);

    expect(RecentActivity::forUser($user))->toBe([]);
});

it('leaves out documents of a warehouse the user no longer has (their own drafts stay, as on the drafts list)', function () {
    $this->stock->user->warehouses()->detach();
    $user = $this->stock->user->fresh();
    $this->actingAs($user);

    expect(array_column(RecentActivity::forUser($user), 'number'))->toBe([$this->order->doc_no]);
});

it('shows at most eight rows', function () {
    foreach (range(1, 9) as $i) {
        $this->stock->post('IN', [$this->stock->line('BEANS', 1)]);
    }

    expect(RecentActivity::forUser($this->stock->user))->toHaveCount(RecentActivity::LIMIT);
});

it('shows the list on the Home page, linking to each document', function () {
    $this->actingAs($this->stock->user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('home.recent.title'))
        ->assertSee($this->order->doc_no)
        ->assertSee(route('receive.show', $this->receipt->doc_no), false);
});

it('says what will appear for a user who has not worked on anything yet', function () {
    $this->actingAs(userWithRole('ADMIN', $this->stock->warehouse))
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('home.recent.title'))
        ->assertSee(__('home.recent.empty'))
        ->assertDontSee('data-recent-row', false);
});
