<?php

use App\Livewire\NotificationBell;
use App\Livewire\Notifications\NotificationList;
use App\Livewire\Settings\Users;
use App\Mail\DraftEmail;
use App\Mail\NotificationEmail;
use App\Models\CustomerBranch;
use App\Models\StockDraft;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\PostingService;
use App\Support\Notifications\Notifier;
use App\Support\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    Mail::fake();
    $this->stock = Stock::create();
    $this->stock->post('IN', [$this->stock->line('BEANS', 50)]);
    $this->admin = $this->stock->user;
    $this->manager = userWithRole('MANAGER', $this->stock->warehouse);
    $this->operator = $this->stock->requester();
    $this->posting = app(PostingService::class);
    $this->drafts = app(DraftService::class);
});

/**
 * A Damage record of $qty Piece over a limit of 10, made by $by (the operator by default).
 */
function damageOverLimit(Stock $stock, ?User $by = null, float $qty = 12): StockTransaction
{
    DB::table('approval_threshold')->insertOrIgnore(['scope' => 'GLOBAL', 'transaction_type' => 'DAMAGE', 'qty_base' => 10]);

    return $stock->post('DAMAGE', [$stock->line('BEANS', $qty)], $by, reason: 'HANDLING');
}

/**
 * @return list<string> ids of the users with a notification about $entity / $id
 */
function notifiedAbout(string $entity, string $id, ?string $event = null): array
{
    return DB::table('notification')->where('entity', $entity)->where('entity_id', $id)
        ->when($event, fn ($query) => $query->where('event', $event))
        ->orderBy('user_id')->pluck('user_id')->map(fn ($userId) => (string) $userId)->all();
}

/**
 * @param  list<User>  $users
 * @return list<string>
 */
function idsOf(array $users): array
{
    return collect($users)->map(fn (User $user) => (string) $user->id)->sort()->values()->all();
}

function unreadOf(User $user): int
{
    return DB::table('notification')->where('user_id', $user->id)->whereNull('read_at')->whereNull('resolved_at')->count();
}

function draftFor(object $test, string $type = 'OUT', int $qty = 4, ?string $version = null): DraftInput
{
    $test->branch ??= CustomerBranch::create(['customer_id' => $test->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);

    return new DraftInput($type, $test->stock->customer->id, $test->branch->id, $test->stock->warehouse->id, CarbonImmutable::today(), null,
        ['r1' => new DraftLineInput($test->stock->id('BEANS'), 'PC', $qty, null, [])], null, $version,
        deliveryDate: $type === 'OUT' ? CarbonImmutable::today() : null, priority: $type === 'OUT' ? 'NORMAL' : null);
}

function portalUser(Stock $stock): User
{
    $portal = userWithRole('CUSTOMER', $stock->warehouse);
    $portal->forceFill(['customer_id' => $stock->customer->id])->save();

    return $portal;
}

// Approval requests

it('tells every approver of the warehouse about a damage sent for approval, and no one else', function () {
    $elsewhere = userWithRole('MANAGER', Warehouse::factory()->create());
    $inactive = userWithRole('MANAGER', $this->stock->warehouse);
    $inactive->forceFill(['active' => false])->save();
    $otherOperator = userWithRole('OPERATOR', $this->stock->warehouse);
    $noEmail = userWithRole('MANAGER', $this->stock->warehouse);
    $noEmail->forceFill(['notify_approvals' => false])->save();

    $damage = damageOverLimit($this->stock);

    expect($damage->status)->toBe('PENDING_APPROVAL')
        ->and(notifiedAbout('stock_transaction', $damage->id, 'approval.requested'))->toBe(idsOf([$this->admin, $this->manager, $noEmail]))
        ->and(notifiedAbout('stock_transaction', $damage->id))->not->toContain((string) $this->operator->id, (string) $elsewhere->id, (string) $inactive->id, (string) $otherOperator->id);

    $emailed = Mail::queued(NotificationEmail::class)->flatMap(fn (NotificationEmail $mail) => array_column($mail->to, 'address'))->sort()->values()->all();
    expect($emailed)->toBe(collect([$this->admin->email, $this->manager->email])->sort()->values()->all());

    $html = (new NotificationEmail((string) DB::table('notification')->where('user_id', $this->manager->id)->value('id')))->render();
    expect($html)->toContain($this->operator->full_name.' sent Damage record '.$damage->doc_no.' for approval', 'Open in the app');
});

it('sends nothing when the requester may approve it themselves', function () {
    $damage = damageOverLimit($this->stock, $this->manager);

    expect($damage->status)->toBe('POSTED')
        ->and(DB::table('notification')->count())->toBe(0)
        ->and(Mail::queued(NotificationEmail::class))->toHaveCount(0);
});

it('closes every approver’s notification once one approves, and tells the requester', function () {
    $damage = damageOverLimit($this->stock);
    expect(unreadOf($this->admin))->toBe(1)->and(unreadOf($this->manager))->toBe(1);

    $this->posting->approve($damage, $this->manager);

    expect(unreadOf($this->admin))->toBe(0)
        ->and(unreadOf($this->manager))->toBe(0)
        ->and(DB::table('notification')->where('event', 'approval.requested')->whereNull('resolved_at')->count())->toBe(0)
        ->and(DB::table('notification')->where('user_id', $this->admin->id)->value('outcome'))->toBe('APPROVED')
        ->and(notifiedAbout('stock_transaction', $damage->id, 'approval.approved'))->toBe([(string) $this->operator->id])
        ->and(unreadOf($this->operator))->toBe(1);

    // The other approver is too late: the existing message, nothing new.
    expect(fn () => $this->posting->approve($damage, $this->admin))->toThrow(AlreadyProcessed::class)
        ->and(DB::table('notification')->count())->toBe(3);

    Mail::assertQueued(NotificationEmail::class, fn (NotificationEmail $mail) => $mail->hasTo($this->operator->email));

    $closed = Livewire::actingAs($this->admin)->test(NotificationList::class)->call('loadRows');
    $closed->assertSee('Approved by '.$this->manager->full_name);
});

it('tells the requester a damage was rejected, with the reason', function () {
    $damage = damageOverLimit($this->stock);

    $this->posting->reject($damage, 'Count it again', $this->manager);

    $row = DB::table('notification')->where('user_id', $this->operator->id)->sole();
    expect($row->event)->toBe('approval.rejected')
        ->and($row->kind)->toBe(Notifier::INFO)
        ->and(json_decode($row->params, true)['reason'])->toBe('Count it again')
        ->and(DB::table('notification')->where('user_id', $this->manager->id)->value('outcome'))->toBe('REJECTED');

    $html = (new NotificationEmail((string) $row->id))->render();
    expect($html)->toContain('Damage record '.$damage->doc_no.' was rejected by '.$this->manager->full_name, 'Count it again');
});

it('does not email a requester who turned the results email off, but still notifies them in the app', function () {
    $this->operator->forceFill(['notify_approval_results' => false])->save();
    $damage = damageOverLimit($this->stock);
    Mail::fake();

    $this->posting->approve($damage, $this->manager);

    expect(unreadOf($this->operator))->toBe(1)
        ->and(Mail::queued(NotificationEmail::class))->toHaveCount(0);
});

// Orders and draft receipts

it('tells the confirmers about an order made by someone who cannot confirm it, once per order', function () {
    $portal = portalUser($this->stock);

    $order = $this->drafts->save(draftFor($this), $portal);
    expect(notifiedAbout('stock_draft', $order->id, 'draft.created'))->toBe(idsOf([$this->admin, $this->manager]));

    // Read it, then the order changes: still one row each, unread again, now "changed".
    DB::table('notification')->update(['read_at' => now()]);
    $version = (string) DB::table('stock_draft')->where('id', $order->id)->value('updated_at');
    $this->drafts->save(draftFor($this, qty: 6, version: $version), $portal, $order);

    expect(DB::table('notification')->count())->toBe(2)
        ->and(notifiedAbout('stock_draft', $order->id, 'draft.updated'))->toBe(idsOf([$this->admin, $this->manager]))
        ->and(unreadOf($this->manager))->toBe(1);

    // The manager confirms it: the admin's notification closes.
    $this->drafts->confirm(StockDraft::withoutGlobalScopes()->findOrFail($order->id), $this->manager);

    expect(unreadOf($this->admin))->toBe(0)
        ->and(DB::table('notification')->where('user_id', $this->admin->id)->value('outcome'))->toBe('CONFIRMED');
});

it('tells the confirmers an order was cancelled, with the reason', function () {
    $portal = portalUser($this->stock);
    $order = $this->drafts->save(draftFor($this), $portal);

    $this->drafts->cancel($order, $portal, 'The branch withdrew it');

    $rows = DB::table('notification')->where('entity_id', $order->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('event')->unique()->all())->toBe(['draft.cancelled'])
        ->and($rows->pluck('kind')->unique()->all())->toBe([Notifier::INFO])
        ->and(unreadOf($this->manager))->toBe(1);

    Mail::assertQueued(DraftEmail::class, fn (DraftEmail $mail) => $mail->event === DraftEmail::CANCELLED && $mail->hasTo($this->manager->email));
});

it('tells the draft receipt confirmers, and nobody when a confirmer makes it', function () {
    $portal = portalUser($this->stock);

    $receipt = $this->drafts->save(draftFor($this, 'IN'), $portal);
    expect(notifiedAbout('stock_draft', $receipt->id, 'draft.created'))->toBe(idsOf([$this->admin, $this->manager]));

    $own = $this->drafts->save(draftFor($this, 'IN'), $this->manager);
    expect(notifiedAbout('stock_draft', $own->id))->toBe([]);
});

it('writes nothing when the save fails', function () {
    expect(fn () => $this->drafts->save(draftFor($this, qty: 500), portalUser($this->stock)))->toThrow(InsufficientStock::class)
        ->and(DB::table('notification')->count())->toBe(0);
});

// Bell, page, opening

it('shows the bell count and the latest notifications, and marks them all read', function () {
    damageOverLimit($this->stock);

    Livewire::actingAs($this->manager)->test(NotificationBell::class)
        ->assertSee('1')
        ->assertSee($this->operator->full_name.' sent Damage record')
        ->call('markAllRead');

    expect(unreadOf($this->manager))->toBe(0);
});

it('opens a notification: marks it read and goes to the document', function () {
    $damage = damageOverLimit($this->stock);
    $id = DB::table('notification')->where('user_id', $this->manager->id)->value('id');

    $this->actingAs($this->manager)->get(route('notifications.open', $id))
        ->assertRedirect(route('damage.show', $damage->doc_no));

    expect(DB::table('notification')->where('id', $id)->value('read_at'))->not->toBeNull();

    // Someone else's notification does not open.
    $this->actingAs($this->admin)->get(route('notifications.open', $id))->assertNotFound();
});

it('explains instead of failing when the user lost access to the document', function () {
    damageOverLimit($this->stock);
    $id = DB::table('notification')->where('user_id', $this->manager->id)->value('id');
    $this->manager->warehouses()->sync([Warehouse::factory()->create()->id]);

    $this->actingAs($this->manager)->get(route('notifications.open', $id))
        ->assertRedirect(route('notifications'));

    expect(collect(Notify::flashed())->pluck('message'))->toContain(__('notifications.no_access'));
});

it('lists the user’s notifications in their language, filtered by type', function () {
    $this->manager->forceFill(['preferred_lang' => 'ar'])->save();
    damageOverLimit($this->stock);
    $this->drafts->save(draftFor($this), portalUser($this->stock));

    app()->setLocale('ar');
    $page = Livewire::actingAs($this->manager)->test(NotificationList::class)->call('loadRows');
    $page->assertSee('طلب موافقة على')->assertSee('تمت إضافة');

    $page->set('group', 'approvals')->assertSee('طلب موافقة على')->assertDontSee('تمت إضافة');

    // The "Columns" menu sends the hidden columns on first load.
    $page->set('hiddenColumns', ['when'])->assertOk();
});

it('offers the approval emails in Settings › Users only to those they apply to', function () {
    // A manager approves (and creates) damage records: both settings.
    Livewire::actingAs($this->admin)->test(Users::class)
        ->call('edit', $this->manager->id)
        ->assertSee(__('settings.users.notify_approvals'))
        ->assertSee(__('settings.users.notify_approval_results'))
        ->set('notifyApprovals', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->manager->fresh()->notify_approvals)->toBeFalse()
        ->and($this->manager->fresh()->notify_approval_results)->toBeTrue();

    // An operator only makes requests: no approval request setting.
    Livewire::actingAs($this->admin)->test(Users::class)
        ->call('edit', $this->operator->id)
        ->assertDontSee(__('settings.users.notify_approvals'))
        ->assertSee(__('settings.users.notify_approval_results'));
});

it('deletes notifications older than 90 days', function () {
    damageOverLimit($this->stock);
    DB::table('notification')->where('user_id', $this->admin->id)->update(['created_at' => now()->subDays(Notifier::KEEP_DAYS + 1)]);

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(DB::table('notification')->pluck('user_id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $this->manager->id]);
});
