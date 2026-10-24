<?php

use App\Livewire\Settings\Users;
use App\Mail\DraftEmail;
use App\Models\CustomerBranch;
use App\Models\StockDraft;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\InsufficientStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Stock;

beforeEach(function () {
    Mail::fake();
    $this->stock = Stock::create();
    $this->stock->post('IN', [$this->stock->line('BEANS', 50)]);
    $this->branch = CustomerBranch::create(['customer_id' => $this->stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);
    $this->drafts = app(DraftService::class);

    $this->portal = userWithRole('CUSTOMER', $this->stock->warehouse);
    $this->portal->forceFill(['customer_id' => $this->stock->customer->id])->save();
    $this->manager = userWithRole('MANAGER', $this->stock->warehouse);
});

/**
 * An order (or draft receipt) of $qty Piece of Canned Beans from Beirut Main.
 */
function orderInput(object $test, int $qty = 4, string $type = 'OUT', ?string $requestId = null, ?string $version = null): DraftInput
{
    return new DraftInput($type, $test->stock->customer->id, $test->branch->id, $test->stock->warehouse->id, CarbonImmutable::today(), 'PO 77',
        ['r1' => new DraftLineInput($test->stock->id('BEANS'), 'PC', $qty, null, [])], $requestId, $version);
}

function draftVersion(StockDraft $draft): string
{
    return (string) DB::table('stock_draft')->where('id', $draft->id)->value('updated_at');
}

/**
 * @return list<string> addresses a draft email about $event was queued to
 */
function orderEmailsTo(string $event): array
{
    return Mail::queued(DraftEmail::class, fn (DraftEmail $mail) => $mail->event === $event)
        ->flatMap(fn (DraftEmail $mail) => array_column($mail->to, 'address'))->sort()->values()->all();
}

it('emails a new order to everyone who can confirm it in its warehouse', function () {
    $elsewhere = userWithRole('MANAGER', Warehouse::factory()->create());
    $operator = userWithRole('OPERATOR', $this->stock->warehouse);
    $inactive = userWithRole('MANAGER', $this->stock->warehouse);
    $inactive->forceFill(['active' => false])->save();
    $optedOut = userWithRole('MANAGER', $this->stock->warehouse);
    $optedOut->forceFill(['notify_new_orders' => false])->save();

    $draft = $this->drafts->save(orderInput($this), $this->portal);

    expect(orderEmailsTo(DraftEmail::CREATED))->toBe(collect([$this->stock->user->email, $this->manager->email])->sort()->values()->all())
        ->and(orderEmailsTo(DraftEmail::CREATED))->not->toContain($this->portal->email, $elsewhere->email, $operator->email, $inactive->email, $optedOut->email);

    Mail::assertQueued(DraftEmail::class, fn (DraftEmail $mail) => $mail->draftId === $draft->id);
});

it('also emails the person who made the order when they can confirm it', function () {
    $this->drafts->save(orderInput($this), $this->manager);

    expect(orderEmailsTo(DraftEmail::CREATED))->toContain($this->manager->email);
});

it('emails the confirmers when an order is changed, but not for a save that changes nothing', function () {
    $draft = $this->drafts->save(orderInput($this), $this->portal);

    $this->drafts->save(orderInput($this, version: draftVersion($draft)), $this->portal, $draft);
    expect(orderEmailsTo(DraftEmail::UPDATED))->toBe([]);

    $this->drafts->save(orderInput($this, 6, version: draftVersion($draft)), $this->portal, $draft);
    expect(orderEmailsTo(DraftEmail::UPDATED))->toBe(collect([$this->stock->user->email, $this->manager->email])->sort()->values()->all());

    $html = (new DraftEmail($draft->id, DraftEmail::UPDATED))->render();
    expect($html)->toContain('was changed', '6 Piece', $this->portal->full_name);
});

it('emails the confirmers when an order is cancelled, with the reason', function () {
    $draft = $this->drafts->save(orderInput($this), $this->portal);

    $this->drafts->cancel($draft, $this->portal, 'The branch withdrew it');

    expect(orderEmailsTo(DraftEmail::CANCELLED))->toBe(collect([$this->stock->user->email, $this->manager->email])->sort()->values()->all());

    $html = (new DraftEmail($draft->id, DraftEmail::CANCELLED))->render();
    expect($html)->toContain('was cancelled', 'The branch withdrew it', $this->portal->full_name);
});

it('sends nothing for a repeated submit or a failed save', function () {
    $requestId = (string) Str::uuid();
    $this->drafts->save(orderInput($this, requestId: $requestId), $this->portal);
    $this->drafts->save(orderInput($this, requestId: $requestId), $this->portal);

    expect(Mail::queued(DraftEmail::class))->toHaveCount(2); // admin + manager, once

    expect(fn () => $this->drafts->save(orderInput($this, 500), $this->portal))->toThrow(InsufficientStock::class)
        ->and(Mail::queued(DraftEmail::class))->toHaveCount(2);
});

it('emails a draft receipt when it is added, changed or cancelled to whoever can confirm draft receipts', function () {
    $everyone = collect([$this->stock->user->email, $this->manager->email])->sort()->values()->all();
    // Receipt emails off: still gets the order emails.
    $noReceipts = userWithRole('MANAGER', $this->stock->warehouse);
    $noReceipts->forceFill(['notify_draft_receipts' => false])->save();
    // Order emails off: still gets the receipt emails.
    $noOrders = userWithRole('MANAGER', $this->stock->warehouse);
    $noOrders->forceFill(['notify_new_orders' => false])->save();
    $withNoOrders = collect([...$everyone, $noOrders->email])->sort()->values()->all();

    $receipt = $this->drafts->save(orderInput($this, type: 'IN'), $this->portal);
    expect(orderEmailsTo(DraftEmail::CREATED))->toBe($withNoOrders);

    $this->drafts->save(orderInput($this, 7, 'IN', version: draftVersion($receipt)), $this->portal, $receipt);
    expect(orderEmailsTo(DraftEmail::UPDATED))->toBe($withNoOrders);

    $this->drafts->cancel($receipt, $this->portal, 'Not coming');
    expect(orderEmailsTo(DraftEmail::CANCELLED))->toBe($withNoOrders);

    $html = (new DraftEmail($receipt->id, DraftEmail::CANCELLED))->render();
    expect($html)->toContain('Draft receipt '.$receipt->doc_no, 'was cancelled', 'Not coming', 'From branch', 'Downtown', 'Open draft receipt', route('draft-receipts.show', ['reference' => $receipt->doc_no]))
        ->and($html)->not->toContain('Open order');

    // An order goes to the order list: not to $noOrders, but to $noReceipts.
    Mail::fake();
    $this->drafts->save(orderInput($this), $this->portal);
    expect(orderEmailsTo(DraftEmail::CREATED))->toBe(collect([...$everyone, $noReceipts->email])->sort()->values()->all());
});

it('writes the email in the recipient’s language with a link to the order', function () {
    $this->manager->forceFill(['preferred_lang' => 'ar'])->save();

    $draft = $this->drafts->save(orderInput($this), $this->portal);

    Mail::assertQueued(DraftEmail::class, fn (DraftEmail $mail) => $mail->hasTo($this->manager->email) && $mail->locale === 'ar');

    $html = (new DraftEmail($draft->id, DraftEmail::CREATED))->locale('ar')->render();

    expect($html)->toContain('dir="rtl"', 'طلبية جديدة', $draft->doc_no, 'Downtown', route('orders.show', ['reference' => $draft->doc_no]))
        ->and((new DraftEmail($draft->id, DraftEmail::CREATED))->render())->toContain('New order', 'Acme Foods', '4 Piece', 'Open order');
});

it('lets an administrator turn the emails off for a user who can confirm orders', function () {
    Livewire::actingAs($this->stock->user)->test(Users::class)
        ->call('edit', $this->manager->id)
        ->assertSee(__('settings.users.notify_new_orders'))
        ->set('notifyNewOrders', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->manager->fresh()->notify_new_orders)->toBeFalse();

    // An operator can't confirm orders: the setting isn't offered.
    Livewire::actingAs($this->stock->user)->test(Users::class)
        ->call('edit', userWithRole('OPERATOR', $this->stock->warehouse)->id)
        ->assertDontSee(__('settings.users.notify_new_orders'));
});

it('lets an administrator turn the draft receipt emails off on their own', function () {
    Livewire::actingAs($this->stock->user)->test(Users::class)
        ->call('edit', $this->manager->id)
        ->assertSee(__('settings.users.notify_draft_receipts'))
        ->set('notifyDraftReceipts', false)
        ->call('save')
        ->assertHasNoErrors();

    $manager = $this->manager->fresh();

    expect($manager->notify_draft_receipts)->toBeFalse()
        ->and($manager->notify_new_orders)->toBeTrue()
        ->and(DB::table('audit_log')->where('entity', 'app_user')->where('entity_id', $manager->id)->latest('id')->value('after_data'))
        ->toContain('"notify_draft_receipts": false');

    // An operator can't confirm draft receipts: the setting isn't offered.
    Livewire::actingAs($this->stock->user)->test(Users::class)
        ->call('edit', userWithRole('OPERATOR', $this->stock->warehouse)->id)
        ->assertDontSee(__('settings.users.notify_draft_receipts'));
});
