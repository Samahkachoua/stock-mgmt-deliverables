<?php

namespace Tests\Browser;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * Leaving the item form with unsaved changes asks first, in a real browser
 * (App\Livewire\Concerns\WarnsBeforeLeaving + leaveGuard in resources/js/app.js):
 * Cancel, the back link and the sidebar are held until the user discards the
 * changes; nothing changed, or just saved, goes straight on.
 */
class LeaveFormTest extends DuskTestCase
{
    private static bool $seeded = false;

    private static string $userId = '';

    private static string $itemId = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);

            $stock = Stock::create();
            self::$userId = $stock->user->id;
            self::$itemId = $stock->id('BEANS');
            self::$seeded = true;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
        Product::whereKey(self::$itemId)->update(['name_en' => 'Canned Beans']);
    }

    private function editForm(Browser $browser): Browser
    {
        return $browser->loginAs(self::$userId)
            ->visit('/app/items/'.self::$itemId.'/edit')
            ->waitFor('#field-nameEn', 10);
    }

    private function dialog(): string
    {
        return '[data-confirm-dialog]';
    }

    public function test_cancel_leaves_at_once_when_nothing_changed(): void
    {
        $this->browse(function (Browser $browser) {
            $this->editForm($browser)
                ->clickLink(__('settings.cancel'))
                ->waitForLocation('/app/items', 10)
                ->assertMissing($this->dialog());
        });
    }

    public function test_cancel_and_the_back_link_ask_before_throwing_changes_away(): void
    {
        $this->browse(function (Browser $browser) {
            $this->editForm($browser)->type('#field-nameEn', 'Canned Beans 410g');

            // Cancel: held, the question opens, "Keep it" keeps what was typed.
            $browser->clickLink(__('settings.cancel'))
                ->waitFor($this->dialog(), 10)
                ->assertSee(__('confirm.discard.item.title', ['name' => 'Canned Beans']))
                ->assertPathIs('/app/items/'.self::$itemId.'/edit')
                ->press(__('confirm.keep'))
                ->waitUntilMissing($this->dialog(), 10)
                ->assertInputValue('#field-nameEn', 'Canned Beans 410g')
                ->assertPathIs('/app/items/'.self::$itemId.'/edit');

            // The back link: asks too; "Discard changes" goes on, nothing saved.
            $browser->clickLink(__('items.title'))
                ->waitFor($this->dialog(), 10)
                ->press(__('confirm.discard.item.button'))
                ->waitForLocation('/app/items', 10)
                ->assertMissing($this->dialog());

            $this->assertSame('Canned Beans', Product::find(self::$itemId)->name_en);
        });
    }

    public function test_the_sidebar_and_closing_the_tab_ask_too(): void
    {
        $this->browse(function (Browser $browser) {
            $this->editForm($browser)->type('#field-nameEn', 'Changed');

            $browser->click('#sidebar a[href$="/stock"]')
                ->waitFor($this->dialog(), 10)
                ->assertPathIs('/app/items/'.self::$itemId.'/edit');

            // Closing / reloading the tab: the browser's own question is requested.
            $prevented = $browser->script("const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented;")[0];
            $this->assertTrue($prevented);
        });
    }

    public function test_saving_goes_to_the_list_without_asking_and_shows_the_toast(): void
    {
        $this->browse(function (Browser $browser) {
            $this->editForm($browser)
                ->type('#field-nameEn', 'Canned Beans 420g')
                ->press(__('items.save'))
                ->waitForLocation('/app/items', 10)
                ->assertMissing($this->dialog())
                ->waitForText(__('items.updated', ['name' => 'Canned Beans 420g']), 10);

            $this->assertSame('Canned Beans 420g', Product::find(self::$itemId)->name_en);
        });
    }
}
