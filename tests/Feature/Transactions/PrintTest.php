<?php

use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Support\StorageStatuses;
use App\Support\Transactions\TransactionTypes;
use Tests\Support\Stock;

/*
 * CLAUDE.md "Printing": every details page has an A4 print view in its own
 * layout — no sidebar, top bar or buttons, always light, RTL in Arabic — and
 * the app layout hides its chrome when a normal page is printed.
 */

beforeEach(function () {
    $this->stock = Stock::create();
    $this->tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
    $this->stock->post('IN', [$this->stock->line('BEANS', 50)]);
});

/**
 * One document of the type, posted in the fixture warehouse.
 */
function printable(Stock $stock, Warehouse $tripoli, string $type): StockTransaction
{
    return match (true) {
        TransactionTypes::get($type)->dispatchReceive() => $stock->dispatch([$stock->line('BEANS', 3)], $tripoli),
        TransactionTypes::get($type)->countsStock() => $stock->post($type, [$stock->line('BEANS', 49)], reason: 'STOCK_COUNT_VARIANCE'),
        TransactionTypes::get($type)->hasHeaderField('reason') => $stock->post($type, [$stock->line('BEANS', 2)], reason: 'HANDLING'),
        default => $stock->post($type, [$stock->line('BEANS', 4)]),
    };
}

it('prints every type of document on its own A4 page, without the app around it', function (string $type) {
    $document = printable($this->stock, $this->tripoli, $type);
    $ui = TransactionTypes::get($type);

    $details = $this->actingAs($this->stock->user)
        ->get($ui->route('show', $document->doc_no))
        ->assertOk();
    // The details page's Print button opens the print view.
    $details->assertSee('href="'.$ui->route('print', $document->doc_no).'"', false);

    $this->get($ui->route('print', $document->doc_no))
        ->assertOk()
        ->assertSee($document->doc_no)
        ->assertSee($ui->label('singular'))
        ->assertSee('Canned Beans')
        ->assertSee(__('print.prepared_by'))
        ->assertSee(__('print.received_by'))
        ->assertSee(__('print.printed_by', ['name' => $this->stock->user->full_name, 'date' => '']))
        ->assertSee('@page { size: A4; margin: 12mm; }', false)
        ->assertSee('window.print()', false)
        // No sidebar, top bar or actions.
        ->assertDontSee('aria-label="'.__('nav.main').'"', false)
        ->assertDontSee('wire:id', false)
        ->assertDontSee('<button', false)
        ->assertDontSee('<a ', false);
    // Every type that has screens (read from the file: datasets load before the app boots).
})->with(array_keys((require dirname(__DIR__, 3).'/config/transactions.php')['types']));

it('prints in light colours even for a user who chose the dark theme', function () {
    $this->stock->user->update(['preferred_theme' => 'dark']);
    $issue = printable($this->stock, $this->tripoli, 'OUT');

    $html = $this->actingAs($this->stock->user->fresh())->get(TransactionTypes::get('OUT')->route('print', $issue->doc_no))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-theme="light"')
        ->toContain('color-scheme: light')
        ->not->toMatch('/<html[^>]*class="[^"]*\bdark\b/')
        ->not->toContain('applyTheme');

    // The normal page, by contrast, follows the user's choice.
    $this->get(TransactionTypes::get('OUT')->route('show', $issue->doc_no))->assertSee('class="dark"', false);
});

it('prints right to left in Arabic, with references left to right', function () {
    $this->stock->user->update(['preferred_lang' => 'ar']);
    $issue = printable($this->stock, $this->tripoli, 'OUT');

    $this->actingAs($this->stock->user->fresh())->get(TransactionTypes::get('OUT')->route('print', $issue->doc_no))
        ->assertOk()
        ->assertSee('lang="ar" dir="rtl"', false)
        ->assertSee(__('print.prepared_by', [], 'ar'))
        ->assertSee(__('transactions.types.issue.singular', [], 'ar'))
        ->assertSee('<span dir="ltr" class="tabular text-lg font-semibold">'.$issue->doc_no.'</span>', false);
});

it('does not print a document of a warehouse the user does not work in', function () {
    $issue = printable($this->stock, $this->tripoli, 'OUT');
    $outsider = userWithRole('ADMIN', $this->tripoli);

    $this->actingAs($outsider)
        ->get(TransactionTypes::get('OUT')->route('print', $issue->doc_no))
        ->assertNotFound();
});

it('hides the sidebar, top bar and actions when a normal page is printed', function () {
    $issue = printable($this->stock, $this->tripoli, 'OUT');

    $html = $this->actingAs($this->stock->user)->get(TransactionTypes::get('OUT')->route('show', $issue->doc_no))->getContent();

    expect($html)->toMatch('/<aside[^>]*\bprint:hidden\b/')
        ->toMatch('/<header[^>]*\bprint:hidden\b/')
        // The action buttons (page header), whatever their spacing.
        ->toMatch('/<div class="flex flex-wrap items-center gap-\d print:hidden">/');
});

it('prints each item’s storage status under its name', function () {
    $this->stock->items['BEANS']->update(['storage_status' => 'CHILLED']);
    $issue = printable($this->stock, $this->tripoli, 'OUT');

    $this->actingAs($this->stock->user)->get(TransactionTypes::get('OUT')->route('print', $issue->doc_no))
        ->assertOk()
        ->assertSee(StorageStatuses::label('CHILLED'));
});
