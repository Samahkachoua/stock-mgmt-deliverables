<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * The Home page at 320 to 1920px, in English (light) and Arabic (dark): no
 * sideways scrolling, nothing sticking out of a card, cards of one row equally
 * tall, no lone card in a last row, touch-sized targets and the 1280px cap.
 */
class HomeLayoutTest extends DuskTestCase
{
    private const WIDTHS = [320, 375, 768, 1024, 1440, 1920];

    private static string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$userId === '') {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);
            Cache::store('file')->flush();

            $stock = Stock::create();
            // A long customer name, to see the recent rows wrap instead of overflowing.
            $stock->customer->update(['name' => 'Mediterranean Wholesale Food Distribution Company']);
            Customer::factory()->create(['code' => 'CEDAR', 'name' => 'Cedar Trading']);
            $tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
            $stock->user->warehouses()->attach($tripoli);

            $stock->post('IN', [$stock->line('BEANS', 100)]);
            $stock->post('OUT', [$stock->line('BEANS', 5)]);
            $stock->order([$stock->line('BEANS', 2)]);
            // A transfer from Tripoli's side would arrive here; one dispatched to Tripoli counts there.
            $stock->dispatch([$stock->line('BEANS', 3)], $tripoli);

            self::$userId = $stock->user->id;
        }
    }

    public function test_home_fits_every_width(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId);
            $problems = [];

            foreach ([['en', 'light'], ['ar', 'dark']] as [$language, $theme]) {
                User::whereKey(self::$userId)->update(['preferred_lang' => $language, 'preferred_theme' => $theme]);

                foreach (self::WIDTHS as $width) {
                    (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setDeviceMetricsOverride', [
                        'width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => $width < 768,
                    ]);
                    $browser->visit('/')->waitFor('#home-recent', 10)->pause(250);

                    foreach ($this->check($browser) as $problem) {
                        $problems[] = "{$width}px ($language, $theme): $problem";
                    }

                    if (getenv('DUSK_SCREENSHOTS')) {
                        $browser->screenshot("home/{$language}-{$width}");
                    }
                }
            }

            $this->assertSame([], $problems);
        });
    }

    /**
     * @return list<string>
     */
    private function check(Browser $browser): array
    {
        return $browser->script(<<<'JS'
            const problems = [];
            const doc = document.documentElement;
            if (doc.scrollWidth > doc.clientWidth + 1) problems.push(`page scrolls sideways by ${doc.scrollWidth - doc.clientWidth}px`);

            const content = document.querySelector('main > div > div');
            if (content.getBoundingClientRect().width > 1281) problems.push(`content is ${content.getBoundingClientRect().width}px wide`);

            const cards = [...document.querySelectorAll('section ul > li > a')];
            if (cards.length < 11) problems.push(`only ${cards.length} cards and rows`);

            for (const card of cards) {
                const box = card.getBoundingClientRect();
                const name = card.textContent.trim().replace(/\s+/g, ' ').slice(0, 40);
                if (box.height < 48) problems.push(`"${name}" is ${box.height}px tall`);
                for (const el of card.querySelectorAll('*')) {
                    const inner = el.getBoundingClientRect();
                    if (inner.width === 0) continue;
                    if (inner.left < box.left - 1 || inner.right > box.right + 1) {
                        problems.push(`"${name}": ${el.tagName.toLowerCase()} sticks out`);
                        break;
                    }
                }
            }

            // Card grids: equal heights per row, no lone half-width card at the end.
            for (const grid of document.querySelectorAll('section ul.grid')) {
                const items = [...grid.children].map((li) => li.getBoundingClientRect());
                const rows = {};
                for (const item of items) (rows[Math.round(item.top)] ??= []).push(item);
                const list = Object.values(rows);
                for (const row of list) {
                    if (new Set(row.map((item) => Math.round(item.height))).size > 1) problems.push(`${grid.previousElementSibling.textContent}: uneven heights in a row`);
                }
                const last = list[list.length - 1];
                const lastWidth = last.reduce((sum, item) => sum + item.width, 0);
                if (list.length > 1 && last.length === 1 && lastWidth < grid.getBoundingClientRect().width - 2) {
                    problems.push(`${grid.previousElementSibling.textContent}: lone card in the last row`);
                }
            }

            return problems;
        JS)[0];
    }
}
