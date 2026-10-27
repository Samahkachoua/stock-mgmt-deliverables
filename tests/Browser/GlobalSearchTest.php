<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * The top bar search in a real browser: a field on desktop, a full-screen
 * panel on a phone; arrow keys and Enter open a result.
 */
class GlobalSearchTest extends DuskTestCase
{
    private static bool $seeded = false;

    private static string $userId = '';

    private static string $reference = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);
            Cache::store('file')->flush();

            $stock = Stock::create();
            $receipt = $stock->post('IN', [$stock->line('BEANS', 24), $stock->line('PHONE', 1, serials: ['SN-900'])]);

            self::$userId = $stock->user->id;
            self::$reference = $receipt->doc_no;
            self::$seeded = true;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
    }

    public function test_enter_opens_the_document_found_by_number(): void
    {
        $this->browse(function (Browser $browser) {
            $this->viewport($browser, 'desktop')
                ->loginAs(self::$userId)
                ->visit('/stock')
                ->waitFor('main')
                ->tap(fn (Browser $browser) => $browser->driver->getKeyboard()->sendKeys('/'))
                ->assertFocused('[data-global-search] input')
                ->tap(fn (Browser $browser) => $browser->driver->getKeyboard()->sendKeys(self::$reference))
                ->waitFor('[data-global-search] [role=option]')
                ->keys('[data-global-search] input', '{enter}')
                ->waitForLocation(route('receive.show', self::$reference, false));
        });
    }

    public function test_phone_opens_a_full_screen_panel(): void
    {
        $this->browse(function (Browser $browser) {
            $this->viewport($browser, 'phone')
                ->loginAs(self::$userId)
                ->visit('/stock')
                ->waitFor('main')
                ->click('[data-global-search] button')
                ->type('[data-global-search] input', 'beans')
                ->waitForText('Canned Beans');

            $box = $browser->script("const r = document.querySelector('[data-global-search] input').closest('.max-md\\\\:fixed').getBoundingClientRect(); return [r.width, r.height, window.innerWidth, window.innerHeight];")[0];
            $this->assertSame([$box[2], $box[3]], [$box[0], $box[1]], 'The search panel fills the phone screen');
        });
    }
}
