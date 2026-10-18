<?php

use App\Mail\StockMismatchFound;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Stock;

beforeEach(function () {
    Mail::fake();
    $this->stock = Stock::create();
    $this->stock->post('IN', [$this->stock->line('BEANS', 3, 'BOX')]);
});

it('says all is well when every balance matches its movements', function () {
    $this->artisan('stock:reconcile')->assertSuccessful();

    Mail::assertNothingSent();
});

it('logs and emails the administrators, in their language, when a balance drifts', function () {
    $this->stock->user->update(['preferred_lang' => 'fr']);
    userWithRole('MANAGER', $this->stock->warehouse); // not an admin: not emailed
    Log::spy();

    // Something wrote around PostingService.
    DB::table('stock_balance')->where('product_id', $this->stock->id('BEANS'))->update(['qty_base' => 30]);

    $this->artisan('stock:reconcile')->assertFailed();

    Log::shouldHaveReceived('critical')->withArgs(fn ($message, $context) => str_contains($message, 'does not match')
        && $context['item'] === 'BEANS' && $context['balance'] === 30.0 && $context['movements'] === 36.0)->once();

    Mail::assertSent(StockMismatchFound::class, function (StockMismatchFound $mail) {
        $mail->assertSeeInText('BEANS');

        return $mail->hasTo($this->stock->user->email) && $mail->locale === 'fr' && count($mail->mismatches) === 1;
    });
    Mail::assertSentCount(1);

    // The email reads in French.
    $rendered = (new StockMismatchFound([['warehouse' => 'BEY', 'customer' => 'ACME', 'item' => 'BEANS', 'expiry' => null, 'balance' => 30.0, 'movements' => 36.0]]))->locale('fr')->render();
    expect($rendered)->toContain('solde 30, total des mouvements 36');
});

it('finds movements with no balance row, and sends to RECONCILE_MAIL_TO when set', function () {
    config(['stock.reconcile_mail_to' => 'stock-alerts@example.test']);
    DB::table('stock_balance')->where('product_id', $this->stock->id('BEANS'))->delete();

    $this->artisan('stock:reconcile')->assertFailed();

    Mail::assertSent(StockMismatchFound::class, fn ($mail) => $mail->hasTo('stock-alerts@example.test') && $mail->mismatches[0]['balance'] === 0.0);
});

it('runs every night', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'stock:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *');
});
