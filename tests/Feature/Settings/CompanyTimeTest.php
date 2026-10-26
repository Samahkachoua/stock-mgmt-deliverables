<?php

use App\Livewire\Settings\General;
use App\Livewire\Transactions\TransactionForm;
use App\Support\CompanyTime;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * One company-wide time zone (Settings › General): what "today" is on every
 * form and document, and the time shown for every saved record. Stored times
 * stay UTC.
 */

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('starts on Asia/Beirut and is changed by admins only in Settings › General', function () {
    expect(CompanyTime::zone())->toBe('Asia/Beirut');

    $admin = userWithRole('ADMIN');
    Livewire::actingAs($admin)->test(General::class)
        ->assertSet('timezone', 'Asia/Beirut')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasNoErrors();

    $row = DB::table('app_setting')->where('key', 'timezone')->first();
    expect($row->value)->toBe('America/New_York')
        ->and($row->updated_by)->toBe($admin->id)
        ->and(CompanyTime::zone())->toBe('America/New_York');

    Livewire::actingAs($admin)->test(General::class)
        ->set('timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors('timezone');
    expect(CompanyTime::zone())->toBe('America/New_York');

    $this->actingAs(userWithRole('OPERATOR'))->get(route('settings.general'))->assertForbidden();
});

it('shows the time now in the zone being picked, before saving', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 09:00', 'UTC'));

    Livewire::actingAs(userWithRole('ADMIN'))->test(General::class)
        ->assertSee('22 Oct 2026, 12:00')
        ->set('timezone', 'Europe/Paris')
        ->assertSee('22 Oct 2026, 11:00');
});

it('starts forms on today in the company time zone, not in UTC', function () {
    // 00:30 on 22 Oct in Beirut is still 21 Oct in UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 00:30', 'Asia/Beirut'));
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)], date: CompanyTime::today());

    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'DAMAGE']);

    expect($form->get('date'))->toBe('2026-10-22')
        ->and($form->html())->toContain('max="2026-10-22"');

    // Dated today there: not refused as "in the future", and not backdated either.
    $deliveryNote = $stock->post('DAMAGE', [$stock->line('BEANS', 4)], $stock->user, reason: 'HANDLING', date: CarbonImmutable::parse('2026-10-22'));
    expect($deliveryNote->status)->toBe('POSTED')
        ->and(substr((string) $deliveryNote->getRawOriginal('document_date'), 0, 10))->toBe('2026-10-22');
});

it('numbers documents by the year in the company time zone', function () {
    // 00:30 on 1 Jan 2027 in Beirut is still 2026 in UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 00:30', 'Asia/Beirut'));
    $stock = Stock::create();

    expect($stock->post('IN', [$stock->line('BEANS', 1)], date: CompanyTime::today())->doc_no)->toStartWith('REC-2027-');
});

it('stores times in UTC and shows them in the company time zone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 07:15', 'UTC'));
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10)]);
    // created_at comes from the database clock (not the test clock): pin it.
    DB::table('stock_transaction')->where('id', $receipt->id)->update(['created_at' => '2026-10-22 07:15:00+00']);

    // Written by Laravel at 07:15 UTC: stored as 07:15 UTC.
    expect(DB::selectOne("SELECT to_char(posted_at AT TIME ZONE 'UTC', 'HH24:MI') AS at FROM stock_transaction WHERE id = ?", [$receipt->id])->at)->toBe('07:15')
        ->and(Format::dateTime(CarbonImmutable::parse('2026-10-22 07:15', 'UTC')))->toBe('22 Oct 2026, 10:15');

    $page = fn () => $this->actingAs($stock->user)->get(route('receive.show', $receipt->doc_no))->assertOk();
    $page()->assertSee('22 Oct 2026, 10:15');

    // Another company time zone: the same moment, shown on that clock.
    CompanyTime::set('Europe/Paris', null);
    $page()->assertSee('22 Oct 2026, 09:15')->assertDontSee('22 Oct 2026, 10:15');
});
