<?php

use App\Livewire\Settings\Customers;
use App\Livewire\Transactions\CancelTransaction;
use App\Livewire\Transactions\ReceiveTransfer;
use App\Livewire\Transactions\TransactionForm;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\AlreadyProcessed;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\NotAllowed;
use App\Services\Stock\Exceptions\OnlyExpiredStock;
use App\Services\Stock\Exceptions\SelfApproval;
use App\Services\Stock\Exceptions\StaleCount;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Support\Notify;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Stock;

/*
 * CLAUDE.md "Feedback messages": one toast component and one notify helper;
 * every result and every error says what happened, in the user's language,
 * and never shows a raw error.
 */

/**
 * A new form of $type in the fixture warehouse, with the fixture customer chosen.
 */
function feedbackForm(Stock $stock, string $type = 'OUT'): Testable
{

    return Livewire::actingAs($stock->user)->test(TransactionForm::class, ['type' => $type])
        ->call('chooseCustomer', $stock->customer->id);
}

/**
 * A route that fails with an error nobody planned for.
 */
function brokenRoute(): string
{
    Route::middleware('web')->any('/_test/broken', fn () => throw new RuntimeException('SQLSTATE[42P01]: secret table detail'));

    return '/_test/broken';
}

// The toast component and the helper

it('draws flashed toasts on the next page, top-end, with a role per kind', function () {
    $stock = Stock::create();

    $html = $this->actingAs($stock->user)
        ->withSession([Notify::SESSION_KEY => [
            ['type' => 'success', 'message' => 'Issue ISS-TEST-1 saved', 'url' => '/issue/ISS-TEST-1', 'link' => 'View'],
        ]])
        ->get(route('receive'))->assertOk()->getContent();

    expect($html)
        ->toContain('Issue ISS-TEST-1 saved')
        ->toContain('x-on:toast.window')
        ->toContain('md:end-4 md:top-4') // top-end; full width at the top on a phone
        ->toContain("toast.type === 'error' ? 'alert' : 'status'")
        ->toContain('aria-label="'.__('feedback.region').'"');

    $source = (string) file_get_contents(resource_path('views/components/toasts.blade.php'));

    // Stacked, at most 3; success closes after 10 s, errors stay; closable; icon and text.
    expect($source)->toContain('this.items.length > 3')
        ->toContain("if (item.type !== 'error') setTimeout(() => this.close(item.id), this.seconds * 1000)")
        ->toContain("seconds: @js((int) config('ui.toast_seconds')),")
        ->toContain('x-on:click="close(toast.id)"')
        ->toContain('name="check_circle"')->toContain('name="report"')
        ->toContain("config('ui.statuses.completed')")->toContain("config('ui.statuses.rejected')")
        ->and(config('ui.toast_seconds'))->toBe(10);
});

it('adds up the toasts of one request and forgets those of an earlier one', function () {
    Notify::success('One');
    Notify::error('Two', '/somewhere');

    expect(session(Notify::SESSION_KEY))->toBe([
        ['type' => 'success', 'message' => 'One', 'url' => null, 'link' => null],
        ['type' => 'error', 'message' => 'Two', 'url' => '/somewhere', 'link' => __('feedback.view')],
    ]);

    // The next request: what was flashed before is old.
    session()->ageFlashData();
    Notify::warning('Three');

    expect(array_column(session(Notify::SESSION_KEY), 'message'))->toBe(['Three']);
});

it('has no per-page status banners left', function () {
    $root = resource_path('views');

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        expect((string) file_get_contents($file->getPathname()))->not->toContain("session('status')", $file->getPathname());
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        expect((string) file_get_contents($file->getPathname()))->not->toContain("flash('status'", $file->getPathname());
    }
});

// Success

it('says what was saved, with the reference and a link, after the redirect', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);

    $form = feedbackForm($stock);
    $row = array_key_first($form->get('lines'));
    $form->set("lines.$row.item", $stock->id('BEANS'))->set("lines.$row.qty", '4')->call('save');

    $issue = DB::table('stock_transaction')->where('transaction_type', 'OUT')->first();

    expect(session(Notify::SESSION_KEY))->toBe([[
        'type' => 'success',
        'message' => 'Issue '.$issue->doc_no.' saved',
        'url' => route('issue.show', $issue->doc_no),
        'link' => 'View',
    ]]);
});

it('has its own message for cancelling and for confirming a receipt', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10)]);

    Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')->set('confirmReason', 'Entered by mistake')->call('runConfirmed');

    expect(session(Notify::SESSION_KEY)[0])->toMatchArray([
        'type' => 'success',
        'message' => __('feedback.cancelled', ['type' => __('transactions.types.receive.singular'), 'reference' => $receipt->doc_no]),
    ]);

    // A transfer received in full at its destination.
    $stock->post('IN', [$stock->line('BEANS', 10)]);
    $tripoli = Warehouse::factory()->create(['name' => 'Tripoli']);
    $transfer = $stock->dispatch([$stock->line('BEANS', 10)], $tripoli);
    $receiver = userWithRole('ADMIN', $tripoli);
    session()->forget(Notify::SESSION_KEY);

    Livewire::actingAs($receiver)->test(ReceiveTransfer::class, ['transactionId' => $transfer->id])->call('confirm');

    expect(session(Notify::SESSION_KEY)[0])->toMatchArray([
        'type' => 'success',
        'message' => __('feedback.received', ['reference' => $transfer->doc_no]),
    ]);
});

// Errors

it('summarises invalid rows in a red toast and keeps what was entered', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 10)]);

    $form = feedbackForm($stock);
    $first = array_key_first($form->get('lines'));
    $form->set("lines.$first.item", $stock->id('BEANS'))->set("lines.$first.qty", '500');
    $second = array_key_last($form->get('lines'));
    $form->set("lines.$second.item", $stock->id('PHONE'))->set("lines.$second.qty", '-1');

    $form->call('save')
        ->assertNoRedirect()
        ->assertDispatched('toast', toast: [
            'type' => 'error',
            'message' => trans_choice('feedback.rows_need_attention', 2, ['count' => '2']),
            'url' => null,
            'link' => null,
        ]);

    expect($form->get("lines.$first.qty"))->toBe('500')
        ->and(DB::table('stock_transaction')->where('transaction_type', 'OUT')->count())->toBe(0);
});

it('summarises a failed validation of any form, but not validation while typing', function () {
    $admin = userWithRole('ADMIN');

    Livewire::actingAs($admin)->test(Customers::class)
        ->call('add')
        ->set('form.name', '')
        ->assertNotDispatched('toast')
        ->call('save')
        ->assertHasErrors('form.name')
        ->assertDispatched('toast', fn (string $event, array $params) => $params['toast']['type'] === 'error'
            && preg_match('/^\d+ fields? needs? attention$/', $params['toast']['message']) === 1);
});

it('says how much of which item is available in which warehouse', function () {
    $stock = Stock::create();
    $stock->post('IN', [$stock->line('BEANS', 4)]);

    try {
        $stock->post('OUT', [$stock->line('BEANS', 12)]);
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->getMessage())->toBe('Only 4 Piece of Canned Beans available in Beirut Main.')
            ->and($e->key)->toBe('stock.errors.not_enough')
            ->and($e->params)->toBe(['qty' => '4 Piece', 'item' => 'Canned Beans', 'warehouse' => 'Beirut Main']);
    }

    // Only expired stock left is its own error.
    $stock->post('IN', [$stock->line('YOGURT', 6, expiry: now()->subDays(3)->toDateString())]);

    expect(fn () => $stock->post('OUT', [$stock->line('YOGURT', 1)]))
        ->toThrow(OnlyExpiredStock::class, 'The remaining 1 Box of Yogurt in Beirut Main are expired.');
});

it('gives each business error its own class, built from a translation key', function () {
    foreach ([InsufficientStock::class, OnlyExpiredStock::class, StaleCount::class, SelfApproval::class, AlreadyProcessed::class, NotAllowed::class] as $class) {
        expect(is_subclass_of($class, StockException::class))->toBeTrue($class);
    }

    $error = new SelfApproval('stock.errors.own_document');

    expect($error->key)->toBe('stock.errors.own_document')
        ->and($error->getMessage())->toBe(__('stock.errors.own_document'));

    app()->setLocale('ar');
    expect((new NotAllowed('stock.errors.warehouse_not_allowed'))->getMessage())->toBe(Lang::get('stock.errors.warehouse_not_allowed', [], 'ar'));
});

it('turns the database’s “Negative stock blocked” into an InsufficientStock', function () {
    $raw = new QueryException('pgsql', 'SELECT fn_post_movement(...)', [], new RuntimeException('ERROR: Negative stock blocked: warehouse=1, available=3.0000, requested_out=5.0000'));
    $translate = fn () => $this->translateDatabaseErrors(fn () => throw $raw);

    try {
        $translate->call(app(PostingService::class));
        $this->fail('Expected InsufficientStock');
    } catch (InsufficientStock $e) {
        expect($e->available)->toBe(3.0)
            ->and($e->requested)->toBe(5.0)
            ->and($e->getMessage())->not->toContain('Negative stock')->not->toContain('SELECT');
    }
});

it('shows a business error in a red toast as well as on the page', function () {
    $stock = Stock::create();
    $receipt = $stock->post('IN', [$stock->line('BEANS', 10)]);
    $stock->post('OUT', [$stock->line('BEANS', 4)]);

    // Part of the receipt has gone out: it can't be cancelled.
    Livewire::actingAs($stock->user)->test(CancelTransaction::class, ['transactionId' => $receipt->id])
        ->call('ask')
        ->assertNoRedirect()
        ->assertSet('problem', fn (?string $problem) => filled($problem))
        ->assertDispatched('toast', fn (string $event, array $params) => $params['toast']['type'] === 'error'
            && str_contains($params['toast']['message'], 'Canned Beans'));
});

// Unexpected errors

it('answers an unexpected error with a reference only, and logs the rest under it', function () {
    config(['app.debug' => false]);
    Log::spy();
    $stock = Stock::create();

    $response = $this->actingAs($stock->user)
        ->withHeaders(['X-Livewire' => ''])
        ->postJson(brokenRoute(), ['components' => [['updates' => ['password' => 'hunter2', 'search' => 'beans'], 'calls' => [['method' => 'save']]]]])
        ->assertStatus(500);

    $message = $response->json('toast.message');
    preg_match('/ERR-[0-9A-F]{6}/', (string) $message, $reference);

    expect($response->json('toast.type'))->toBe('error')
        ->and($reference)->not->toBeEmpty()
        ->and($message)->toBe(__('feedback.errors.unexpected', ['reference' => $reference[0]]))
        // Nothing of the error itself.
        ->and($response->getContent())->not->toContain('SQLSTATE')->not->toContain('RuntimeException')->not->toContain('.php');

    Log::shouldHaveReceived('error')->withArgs(function (string $line, array $context) use ($reference, $stock) {
        return str_contains($line, $reference[0])
            && $context['reference'] === $reference[0]
            && $context['user_id'] === $stock->user->id
            && str_contains($context['url'], '/_test/broken')
            && $context['exception'] instanceof RuntimeException
            && Arr::get($context, 'input.0.updates.search') === 'beans'
            && Arr::get($context, 'input.0.updates.password') === '[hidden]';
    })->once();
});

it('shows the reference, not the error, on the error page', function () {
    config(['app.debug' => false]);
    Log::spy();

    $html = $this->get(brokenRoute())->assertStatus(500)->getContent();
    preg_match('/ERR-[0-9A-F]{6}/', $html, $reference);

    expect($reference)->not->toBeEmpty()
        ->and($html)->not->toContain('SQLSTATE')->not->toContain('RuntimeException');

    Log::shouldHaveReceived('error')->withArgs(fn (string $line) => str_contains($line, $reference[0]))->once();
});

it('never runs in debug mode outside local', function () {
    $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain("if (! \$this->app->environment('local', 'testing')) {")
        ->toContain("config(['app.debug' => false]);");
});

// Connection lost, session expired

it('replaces Livewire’s failure dialogs with toasts', function () {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain("window.Livewire.hook('request'")
        ->toContain('status === 419')->toContain("key: 'session_expired'")
        ->toContain('content === null')->toContain("key: 'connection'")
        ->toContain('status === 403')->toContain("key: 'not_allowed'")
        ->toContain('preventDefault()');

    // The messages are on the page already: the server can't be asked for them then.
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect($html)->toContain('session_expired')->toContain('connection')->toContain('not_allowed');
});

// Languages

it('has every feedback and error message in each language, with the same parameters', function (string $file) {
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $found);
        $names = array_unique($found[0]);
        sort($names);

        return $names;
    };

    foreach (Arr::dot(Lang::get($file, [], 'en')) as $key => $english) {
        foreach (['ar', 'fr'] as $locale) {
            expect(Lang::hasForLocale("$file.$key", $locale))->toBeTrue("$file.$key is missing in $locale");

            $translated = Lang::get("$file.$key", [], $locale);

            expect($placeholders($translated))->toBe($placeholders($english), "$file.$key has other parameters in $locale");
        }
    }
})->with(['feedback', 'stock', 'transfers']);
