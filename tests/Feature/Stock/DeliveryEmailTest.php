<?php

use App\Mail\DeliveryConfirmedEmail;
use App\Models\CustomerBranch;
use App\Models\StockTransaction;
use App\Services\Stock\DeliveredLine;
use App\Services\Stock\Exceptions\PostingRejected;
use App\Services\Stock\PostingService;
use App\Support\Deliveries\DeliveryNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Stock;

/*
 * When a Delivery note made from an Order is confirmed delivered, the manager
 * of the order's branch (Settings › Branches, "Manager email") gets an email
 * with the delivery's details. No manager email: no email.
 */

beforeEach(function () {
    Mail::fake();
    $this->stock = Stock::create();
    $this->stock->post('IN', [$this->stock->line('BEANS', 40, 'BOX')]);
    // The branch Stock::order() uses.
    $this->branch = CustomerBranch::create([
        'customer_id' => $this->stock->customer->id, 'code' => 'MAIN', 'name' => 'Harbour', 'manager_email' => 'rana@acme.test',
    ]);
});

/**
 * An order of 20 Box + 5 Piece of beans, confirmed and dispatched (driver,
 * phone, truck): its Delivery note and line ids.
 *
 * @return array{0: StockTransaction, 1: string, 2: string}
 */
function dispatchedOrder(Stock $stock): array
{
    $note = $stock->confirmOrder([$stock->line('BEANS', 20, 'BOX'), $stock->line('BEANS', 5)]);
    app(PostingService::class)->dispatchDelivery($note, 'Karim', '+961 3 123 456', 'TR-77', $stock->user);
    [$boxes, $pieces] = DB::table('stock_transaction_line')->where('transaction_id', $note->id)->orderBy('line_no')->pluck('id')->all();

    return [$note, (string) $boxes, (string) $pieces];
}

it('emails the branch manager once the delivery is confirmed, with what was delivered', function () {
    [$note, $boxes, $pieces] = dispatchedOrder($this->stock);

    // Dispatching sends no delivery email yet.
    Mail::assertNotQueued(DeliveryConfirmedEmail::class);

    app(PostingService::class)->deliver($note, [$boxes => new DeliveredLine('18', 'DAMAGED'), $pieces => new DeliveredLine('5')], $this->stock->user);

    Mail::assertQueued(DeliveryConfirmedEmail::class, fn (DeliveryConfirmedEmail $mail) => $mail->hasTo('rana@acme.test') && $mail->transactionId === $note->id);
    expect(Mail::queued(DeliveryConfirmedEmail::class))->toHaveCount(1);

    $order = DB::table('stock_draft')->where('id', $note->fresh()->draft_id)->value('doc_no');
    $mail = new DeliveryConfirmedEmail($note->id);
    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe(__('deliveries.mail.subject', ['order' => $order, 'reference' => $note->doc_no, 'branch' => 'Harbour', 'customer' => 'Acme Foods']))
        ->and($html)->toContain($note->doc_no, $order, 'Harbour', 'Acme Foods', 'Beirut Main', 'Karim', '+961 3 123 456', 'TR-77')
        // Ordered 20 Box, delivered 18, and why less.
        ->toContain('Canned Beans', '20 Box', '18 Box', e(__('deliveries.reasons.DAMAGED')), '5 Piece');
});

it('sends nothing when the branch has no manager email', function () {
    $this->branch->update(['manager_email' => null]);
    [$note, $boxes, $pieces] = dispatchedOrder($this->stock);

    app(PostingService::class)->deliver($note, [$boxes => new DeliveredLine('20'), $pieces => new DeliveredLine('5')], $this->stock->user);

    expect($note->fresh()->status)->toBe('POSTED');
    Mail::assertNotQueued(DeliveryConfirmedEmail::class);
});

it('sends nothing when the delivery is refused', function () {
    [$note, $boxes] = dispatchedOrder($this->stock);

    // A line left out: nothing is saved, nothing is sent.
    expect(fn () => app(PostingService::class)->deliver($note, [$boxes => new DeliveredLine('20')], $this->stock->user))
        ->toThrow(PostingRejected::class);

    expect($note->fresh()->status)->toBe('DISPATCHED');
    Mail::assertNotQueued(DeliveryConfirmedEmail::class);
});

it('writes the email in the company language, right to left in Arabic', function () {
    [$note, $boxes, $pieces] = dispatchedOrder($this->stock);
    app(PostingService::class)->deliver($note, [$boxes => new DeliveredLine('20'), $pieces => new DeliveredLine('5')], $this->stock->user);

    Mail::assertQueued(DeliveryConfirmedEmail::class, fn (DeliveryConfirmedEmail $mail) => $mail->locale === config('app.locale'));

    $html = (new DeliveryConfirmedEmail($note->id))->locale('ar')->render();

    expect($html)->toContain('dir="rtl"', 'تم التسليم', $note->doc_no);
});

it('confirms the delivery even when the email can’t be queued', function () {
    Log::spy();
    [$note, $boxes, $pieces] = dispatchedOrder($this->stock);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('mail server down'));

    app(PostingService::class)->deliver($note, [$boxes => new DeliveredLine('20'), $pieces => new DeliveredLine('5')], $this->stock->user);

    expect($note->fresh()->status)->toBe('POSTED');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Delivery email not sent')->once();
});

it('only writes to the manager of a delivery note made from an order', function () {
    [$note] = dispatchedOrder($this->stock);
    $notifier = app(DeliveryNotifier::class);

    expect($notifier->recipient($note->id))->toBe('rana@acme.test');

    DB::table('stock_transaction')->where('id', $note->id)->update(['draft_id' => null]);

    expect($notifier->recipient($note->id))->toBeNull();
});
