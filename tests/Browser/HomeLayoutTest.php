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
 * Both Home pages (staff and customer user) at 320 to 1920px, in English
 * (light) and Arabic (dark): no sideways scrolling, nothing sticking out of a
 * card or row, cards of one row equally tall, no lone card in a last row,
 * touch-sized targets and the 1280px cap.
 */
class HomeLayoutTest extends DuskTestCase
{
    private const WIDTHS = [320, 375, 768, 1024, 1440, 1920];

    private static string $userId = '';

    private static string $customerUserId = '';

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

            $stock->post('IN', [$stock->line('BEANS', 100), $stock->line('YOGURT', 20, expiry: now()->addDays(12)->toDateString()), $stock->line('YOGURT', 30, expiry: now()->addDays(75)->toDateString())]);
            $stock->post('OUT', [$stock->line('BEANS', 5)]);
            $stock->order([$stock->line('BEANS', 2)]);
            // A transfer from Tripoli's side would arrive here; one dispatched to Tripoli counts there.
            $stock->dispatch([$stock->line('BEANS', 3)], $tripoli);

            self::$userId = $stock->user->id;

            $portal = userWithRole('CUSTOMER', $stock->warehouse);
            $portal->forceFill(['customer_id' => $stock->customer->id])->save();
            self::$customerUserId = $portal->id;
        }
    }

    public function test_staff_home_fits_every_width(): void
    {
        $this->assertHomeFits(self::$userId, 'staff', 8);
    }

    public function test_customer_home_fits_every_width(): void
    {
        $this->assertHomeFits(self::$customerUserId, 'customer', 4);
    }

    private function assertHomeFits(string $userId, string $page, int $minimum): void
    {
        $this->browse(function (Browser $browser) use ($userId, $page, $minimum) {
            $browser->logout()->loginAs($userId);
            $problems = [];

            foreach ([['en', 'light'], ['ar', 'dark']] as [$language, $theme]) {
                User::whereKey($userId)->update(['preferred_lang' => $language, 'preferred_theme' => $theme]);

                foreach (self::WIDTHS as $width) {
                    (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setDeviceMetricsOverride', [
                        'width' => $width, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => $width < 768,
                    ]);
                    $browser->visit('/')->waitFor('[data-home="'.$page.'"] #home-recent', 10)->pause(250);

                    foreach ($this->check($browser, $minimum) as $problem) {
                        $problems[] = "{$width}px ($language, $theme): $problem";
                    }

                    if (getenv('DUSK_SCREENSHOTS')) {
                        $browser->screenshot("home/{$page}-{$language}-{$width}");
                    }
                }
            }

            $this->assertSame([], $problems);
        });
    }

    /**
     * @return list<string>
     */
    private function check(Browser $browser, int $minimum): array
    {
        return $browser->script('const minimum = '.$minimum.';'.<<<'JS'
            const problems = [];
            const doc = document.documentElement;
            if (doc.scrollWidth > doc.clientWidth + 1) problems.push(`page scrolls sideways by ${doc.scrollWidth - doc.clientWidth}px`);

            const content = document.querySelector('[data-home]');
            if (content.getBoundingClientRect().width > 1281) problems.push(`content is ${content.getBoundingClientRect().width}px wide`);

            // Cards and rows at least 48px tall; buttons and chips at least the control height.
            const cards = [...document.querySelectorAll('[data-attention], [data-quick-start], [data-recent-row], [data-queue-row], [data-open-order], [data-recent-search]')];
            if (cards.length < minimum) problems.push(`only ${cards.length} cards and rows`);

            for (const card of cards) {
                const box = card.getBoundingClientRect();
                const name = card.textContent.trim().replace(/\s+/g, ' ').slice(0, 40);
                const least = card.matches('[data-quick-start="more"], [data-recent-search], [data-home="customer"] [data-quick-start]') ? 36 : 48;
                if (box.height < least) problems.push(`"${name}" is ${box.height}px tall`);
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
                    if (new Set(row.map((item) => Math.round(item.height))).size > 1) problems.push(`${grid.closest('section').id}: uneven heights in a row`);
                }
                const last = list[list.length - 1];
                const lastWidth = last.reduce((sum, item) => sum + item.width, 0);
                if (list.length > 1 && last.length === 1 && lastWidth < grid.getBoundingClientRect().width - 2) {
                    problems.push(`${grid.closest('section').id}: lone card in the last row`);
                }
            }

            return problems;
        JS)[0];
    }
}
