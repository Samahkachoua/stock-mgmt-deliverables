<?php

use App\Livewire\Reports\ReportPage;
use App\Livewire\Transactions\TransactionForm;
use App\Livewire\Transactions\TransactionList;
use App\Services\Stock\Document;
use App\Services\Stock\PostingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ReportData;
use Tests\Support\Stock;

/*
 * CLAUDE.md "Loading states": one set of loaders for every form, list and
 * export; a form submitted twice saves once.
 */

/**
 * The Receive list of the fixture warehouse.
 */
function loadingList(Stock $stock): Testable
{

    return Livewire::actingAs($stock->user)->test(TransactionList::class, ['type' => 'IN']);
}

/**
 * A new Issue form of the fixture warehouse with one filled line.
 */
function issueForm(Stock $stock, string $qty): Testable
{

    $form = Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => 'OUT'])
        ->call('chooseCustomer', $stock->customer->id);
    $row = array_key_first($form->get('lines'));

    return $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", $qty);
}

// Double submit

it('creates one transaction when the same token is posted twice', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);
    $token = (string) Str::uuid();
    $issue = fn () => new Document('OUT', $stock->warehouse->id, $stock->customer->id, CarbonImmutable::today(), null, ['r1' => $stock->line('BEANS', 4)], requestId: $token);

    $first = app(PostingService::class)->post($issue(), $stock->user);
    $second = app(PostingService::class)->post($issue(), $stock->user);

    expect($second->id)->toBe($first->id)
        ->and(DB::table('stock_transaction')->where('transaction_type', 'OUT')->count())->toBe(1)
        ->and(DB::table('stock_transaction')->where('id', $first->id)->value('client_request_id'))->toBe($token)
        ->and(DB::table('stock_movement')->where('transaction_id', $first->id)->count())->toBe(1)
        ->and($stock->balance('BEANS'))->toBe(6.0)
        ->and(Stock::ledgerMismatches())->toBe([]);
});

it('posts documents without a token, or with different tokens, separately', function () {
    $stock = Stock::create();

    $stock->post('IN', [$stock->line('BEANS', 1)]);
    $stock->post('IN', [$stock->line('BEANS', 1)]);

    foreach ([Str::uuid(), Str::uuid()] as $token) {
        app(PostingService::class)->post(
            new Document('IN', $stock->warehouse->id, $stock->customer->id, CarbonImmutable::today(), null, ['r1' => $stock->line('BEANS', 1)], requestId: (string) $token),
            $stock->user,
        );
    }

    expect(DB::table('stock_transaction')->count())->toBe(4)
        ->and($stock->balance('BEANS'))->toBe(4.0);
});

it('refuses a second row with the same token in the database', function () {
    $stock = Stock::create();
    $token = (string) Str::uuid();
    $first = app(PostingService::class)->post(
        new Document('IN', $stock->warehouse->id, $stock->customer->id, CarbonImmutable::today(), null, ['r1' => $stock->line('BEANS', 1)], requestId: $token),
        $stock->user,
    );

    expect(fn () => DB::transaction(fn () => DB::table('stock_transaction')->insert([
        'doc_no' => 'DUPLICATE-1',
        'transaction_type' => 'IN',
        'status' => 'POSTED',
        'warehouse_id' => $first->warehouse_id,
        'customer_id' => $first->customer_id,
        'created_by' => $stock->user->id,
        'client_request_id' => $token,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('saves once when the same form is submitted twice', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);

    $form = issueForm($stock, '4');
    $form->call('save')->assertHasNoErrors();
    $form->call('save')->assertHasNoErrors();

    $issues = DB::table('stock_transaction')->where('transaction_type', 'OUT')->get();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->client_request_id)->toBe($form->get('requestId'))
        ->and($stock->balance('BEANS'))->toBe(6.0);
    $form->assertRedirect(route('issue.show', $issues[0]->doc_no));

    // Another form is another document.
    $other = issueForm($stock, '4');
    expect($other->get('requestId'))->not->toBe($form->get('requestId'));
    $other->call('save')->assertHasNoErrors();

    expect(DB::table('stock_transaction')->where('transaction_type', 'OUT')->count())->toBe(2)
        ->and($stock->balance('BEANS'))->toBe(2.0);
});

// Submit buttons

it('disables the submit button and shows “Saving…” while the form is being saved', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);
    $html = issueForm($stock, '4')->html();

    preg_match('/<button\s[^>]*type="submit"[^>]*>.*?<\/button>/s', $html, $button);

    expect($button)->not->toBeEmpty()
        ->and($button[0])
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="save"')
        ->toContain('wire:loading.remove')
        ->toContain('wire:loading.inline-flex')
        ->toContain('animate-spin')
        ->toContain(__('app.loading.saving'))
        ->toContain(__('transactions.types.issue.save'));

    // The rest of the form is read-only while it is saved.
    expect($html)->toMatch('/<fieldset\s[^>]*wire:loading\.attr="disabled"[^>]*wire:target="save, runConfirmed"/');
});

it('gives action buttons the same loading state as form buttons', function () {
    $html = Blade::render('<x-submit-button target="approve" action="approve" icon="check_circle">Approve</x-submit-button>');

    expect($html)
        ->toContain('type="button"')
        ->toContain('wire:click="approve"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="approve"')
        ->toContain(__('app.loading.saving'));
});

it('uses the shared submit button and spinner in every view', function () {
    $views = [];
    $root = resource_path('views');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $views[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
    }

    // Every action that saves or posts goes through x-submit-button.
    foreach ([
        'livewire/transactions/form.blade.php' => 'target="save"',
        'livewire/transactions/decide-approval.blade.php' => 'target="approve"',
        'livewire/transactions/receive-transfer.blade.php' => 'target="confirm"',
        'components/confirm-modal.blade.php' => 'target="runConfirmed"',
        'livewire/items/form.blade.php' => 'target="save"',
        'components/master-data/editor.blade.php' => 'target="save"',
        'components/report/export-buttons.blade.php' => 'app.loading.preparing_file',
    ] as $view => $needle) {
        expect($views[$view])->toContain('<x-submit-button')->toContain($needle);
    }

    foreach ($views as $name => $source) {
        // No page draws its own spinner...
        if ($name !== 'components/spinner.blade.php') {
            expect($source)->not->toContain('animate-spin', $name);
        }

        // ...or its own submit button inside a Livewire form.
        if ($name !== 'components/submit-button.blade.php' && str_contains($source, 'wire:submit')) {
            expect($source)->not->toContain('type="submit"', $name);
        }
    }
});

// Lists and reports

it('keeps the rows rendered, dimmed under a progress bar, while a list reloads', function () {
    $stock = Stock::create();
    $references = [];

    for ($i = 0; $i < 3; $i++) {
        $references[] = $stock->post('IN', [$stock->line('BEANS', 1)])->doc_no;
    }

    $html = loadingList($stock)->set('status', 'POSTED')->set('search', 'REC')->html();
    $region = substr($html, (int) strpos($html, 'wire:loading.delay.attr="aria-busy"'));

    expect($html)->toContain('wire:loading.delay.attr="aria-busy"')
        ->and($region)
        ->toContain('role="progressbar"')
        ->toContain('wire:loading.delay.class="opacity-50 pointer-events-none"')
        ->toContain(...$references)
        // Nothing in the table is removed or swapped for a spinner while loading.
        ->not->toContain('wire:loading.remove')
        ->not->toContain('animate-pulse');

    // The rows sit inside the element that is dimmed.
    expect(strpos($region, 'opacity-50 pointer-events-none'))->toBeLessThan(strpos($region, $references[0]));

    // The search field shows its spinner in place of the search icon.
    expect($html)->toMatch('/wire:loading\.delay\.remove="" wire:target="search"/')
        ->toMatch('/animate-spin[^>]*wire:loading\.delay="" wire:target="search"/');
});

it('shows skeleton rows matching the columns on the first load, then the rows', function () {
    config(['ui.defer_rows' => true]);
    $stock = Stock::create();
    $reference = $stock->post('IN', [$stock->line('BEANS', 1)])->doc_no;

    $list = loadingList($stock);
    $columns = 7 + 1; // Reference … Created by, and the row arrow

    expect($list->get('rowsLoaded'))->toBeFalse()
        ->and($list->html())
        ->toContain('wire:init="loadRows"')
        ->toContain('aria-busy="true"')
        ->not->toContain($reference)
        ->and(substr_count($list->html(), 'animate-pulse'))->toBe(6 * $columns);

    $list->call('loadRows');

    expect($list->html())->toContain($reference)
        ->not->toContain('animate-pulse')
        ->not->toContain('wire:init="loadRows"');
});

it('keeps the remembered page through the skeleton first load', function () {
    config(['ui.defer_rows' => true]);
    $stock = Stock::create();

    for ($i = 0; $i < 10; $i++) {
        $stock->post('IN', [$stock->line('BEANS', 1)]);
    }

    session(['list_state.receive' => ['properties' => [], 'page' => 2]]);

    expect(loadingList($stock)->call('loadRows')->viewData('rows')->currentPage())->toBe(2);
});

it('shows a spinner and “Preparing file…” on the export buttons', function () {
    $data = ReportData::create();

    $html = Livewire::actingAs($data->admin)->test(ReportPage::class, ['report' => 'damage'])->html();

    expect($html)
        ->toContain('wire:click="export(&#039;xlsx&#039;)"')
        ->toContain('wire:target="export(&#039;pdf&#039;)"')
        ->toContain(__('app.loading.preparing_file'))
        // Exporting doesn't dim the table.
        ->toContain('wire:target.except="export"');
});

// Navigation

it('navigates from the sidebar with wire:navigate and a brand-coloured progress bar', function () {
    $stock = Stock::create();

    $html = $this->actingAs($stock->user)
        ->get(route('receive'))->assertOk()->getContent();

    preg_match('/<aside\b.*?<\/aside>/s', $html, $sidebar);
    preg_match_all('/<a\s[^>]*>/s', $sidebar[0], $links);

    expect($links[0])->not->toBeEmpty();

    foreach ($links[0] as $link) {
        expect($link)->toContain('wire:navigate');
    }

    expect((string) file_get_contents(resource_path('css/app.css')))->toContain('--livewire-progress-bar-color: var(--brand)');
});

// Labels

it('has every loading label in each language', function (string $locale) {
    foreach (['app.loading.saving', 'app.loading.list', 'app.loading.preparing_file', 'auth.signing_in', 'imports.upload.sending'] as $key) {
        expect(Lang::hasForLocale($key, $locale))->toBeTrue("$key is missing in $locale")
            ->and(trim((string) Lang::get($key, [], $locale)))->not->toBe('');

        if ($locale !== 'en') {
            expect(Lang::get($key, [], $locale))->not->toBe(Lang::get($key, [], 'en'), "$key is not translated in $locale");
        }
    }
})->with(['en', 'ar', 'fr']);
