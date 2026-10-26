<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * CLAUDE.md "Density": how many rows the Delivery notes list, the Stock page and the
 * New Delivery Note lines grid show without scrolling, at 1440x900 and 1280x800 (at
 * least 30% more than before the density tokens; the numbers are written to
 * storage/logs/density-<DENSITY_LABEL>.json), 36px controls with a mouse and
 * 44px on touch, and no text under 12px (13px in Arabic).
 *
 *   visible  rows fully on screen (a list shows at most one page)
 *   fit      rows of that height that fit between the first row and the
 *            bottom of the screen (above the sticky footer on the form):
 *            what the screen holds, whatever the page size
 */
class DensityTest extends DuskTestCase
{
    private static bool $seeded = false;

    private static string $userId = '';

    /** @var array<string, array{0: int, 1: int}> */
    private const SCREENS = ['1440x900' => [1440, 900], '1280x800' => [1280, 800]];

    /** Rows that fit before the density tokens (measured 2026-10-17, English, light, with a mouse). */
    private const BEFORE = [
        '1440x900' => ['deliveryNotes' => 8, 'stock' => 6, 'lines' => 3],
        '1280x800' => ['deliveryNotes' => 6, 'stock' => 5, 'lines' => 2],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);

            $stock = Stock::create();
            $units = Unit::pluck('id', 'code');

            // Enough items for more than a page of the Stock list.
            $lines = [$stock->line('BEANS', 480)];
            for ($i = 1; $i <= 30; $i++) {
                $code = sprintf('DENS%02d', $i);
                $product = Product::create([
                    'customer_id' => $stock->customer->id, 'sku' => $code, 'name_en' => 'Density item '.$i,
                    'base_unit_id' => $units['PC'], 'track_expiry' => false, 'track_serial' => false,
                ]);
                $product->units()->create(['unit_id' => $units['PC'], 'factor_to_base' => 1, 'is_default' => true]);
                $product->units()->create(['unit_id' => $units['BOX'], 'factor_to_base' => 12, 'is_default' => false]);
                $stock->items[$code] = $product;
                $lines[] = $stock->line($code, 100);
            }
            $stock->post('IN', $lines);

            // More delivery notes than a page of the Delivery notes list.
            for ($i = 0; $i < 20; $i++) {
                $stock->post('OUT', [$stock->line('BEANS', 2), $stock->line(sprintf('DENS%02d', $i + 1), 1)]);
            }

            self::$userId = $stock->user->id;
            self::$seeded = true;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
    }

    private function screen(Browser $browser, int $width, int $height, bool $touch = false): Browser
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false]);
        $tools->execute('Emulation.setTouchEmulationEnabled', ['enabled' => $touch, 'maxTouchPoints' => $touch ? 5 : 1]);

        return $browser;
    }

    /**
     * @return array{visible: int, fit: int, rowHeight: float, firstTop: int}
     */
    private function list(Browser $browser, string $path): array
    {
        $browser->visit($path)->waitFor('main', 10)->pause(350)->waitUntilMissing('tr[aria-hidden="true"]', 10)->pause(200);

        return $browser->script(<<<'JS'
            window.scrollTo(0, 0);
            const rows = [...document.querySelectorAll('table[data-list] tbody tr')];
            const first = rows[0].getBoundingClientRect();
            const rowHeight = (rows[rows.length - 1].getBoundingClientRect().bottom - first.top) / rows.length;
            return {
                visible: rows.filter((row) => row.getBoundingClientRect().bottom <= window.innerHeight + 0.5).length,
                fit: Math.floor((window.innerHeight - first.top) / rowHeight + 0.01),
                rowHeight: Math.round(rowHeight * 10) / 10,
                firstTop: Math.round(first.top),
            };
        JS)[0];
    }

    /**
     * The New Transfer form with three filled lines (and the empty last one).
     *
     * @return array{visible: int, fit: int, rowHeight: float, firstTop: int}
     */
    private function linesGrid(Browser $browser): array
    {
        $customer = Customer::where('code', 'ACME')->value('id');
        $browser->visit('/transfer/new')->waitFor('main', 10)
            ->select('#field-customer', (string) $customer)
            ->waitUntilEnabled('[data-line] input[role="combobox"]');

        foreach (['BEANS', 'DENS01', 'DENS02'] as $n => $code) {
            $line = '[data-line]:nth-child('.($n + 1).')';
            $browser->script("document.querySelector('$line input[role=combobox]').scrollIntoView({ block: 'center' })");
            $browser->click("$line input[role=\"combobox\"]")
                ->type("$line input[role=\"combobox\"]", $code)
                ->pause(700)
                ->waitFor("$line [role=\"option\"]")
                ->click("$line [role=\"option\"]")
                ->waitUntilEnabled("$line input[inputmode=\"numeric\"]")
                ->type("$line input[inputmode=\"numeric\"]", '2')
                ->waitFor('[data-line]:nth-child('.($n + 2).')', 10)
                ->pause(600);
        }

        return $browser->script(<<<'JS'
            document.activeElement?.blur();
            window.scrollTo(0, 0);
            const lines = [...document.querySelectorAll('[data-line]')];
            const bottom = window.innerHeight - document.querySelector('[data-form-footer]').getBoundingClientRect().height;
            const first = lines[0].getBoundingClientRect();
            // A filled line: item, unit, quantity and the "Available" hint under it.
            const rowHeight = (lines[2].getBoundingClientRect().bottom - first.top) / 3;
            return {
                visible: lines.filter((line) => line.getBoundingClientRect().bottom <= bottom + 0.5).length,
                fit: Math.max(0, Math.floor((bottom - first.top) / rowHeight + 0.01)),
                rowHeight: Math.round(rowHeight * 10) / 10,
                firstTop: Math.round(first.top),
            };
        JS)[0];
    }

    public function test_rows_on_screen(): void
    {
        $results = [];

        $this->browse(function (Browser $browser) use (&$results) {
            $browser->loginAs(self::$userId);

            foreach (self::SCREENS as $name => [$width, $height]) {
                $this->screen($browser, $width, $height);
                $results[$name] = [
                    'deliveryNotes' => $this->list($browser, '/delivery-note'),
                    'stock' => $this->list($browser, '/stock'),
                    'lines' => $this->linesGrid($browser),
                ];

                if (getenv('DUSK_SCREENSHOTS')) {
                    $browser->screenshot("density/$name-lines");
                    $this->list($browser, '/delivery-note');
                    $browser->screenshot("density/$name-delivery-notes");
                    $this->list($browser, '/stock');
                    $browser->screenshot("density/$name-stock");
                }
            }
        });

        $label = getenv('DENSITY_LABEL') ?: 'latest';
        file_put_contents(storage_path("logs/density-$label.json"), json_encode($results, JSON_PRETTY_PRINT));

        // At least 30% more rows than before the density tokens.
        foreach (self::BEFORE as $name => $before) {
            foreach ($before as $page => $rows) {
                $this->assertGreaterThanOrEqual((int) ceil($rows * 1.3), $results[$name][$page]['fit'], "$page at $name");
            }
        }
    }

    public function test_controls_are_36px_with_a_mouse_and_44px_on_touch(): void
    {
        $customer = Customer::where('code', 'ACME')->value('id');

        $this->browse(function (Browser $browser) use ($customer) {
            $browser->loginAs(self::$userId);

            foreach ([false => 36, true => 44] as $touch => $height) {
                $this->screen($browser, 1280, 800, (bool) $touch)
                    ->visit('/transfer/new')->waitFor('main', 10)
                    ->select('#field-customer', (string) $customer)
                    ->waitUntilEnabled('[data-line] input[role="combobox"]');

                $sizes = $browser->script(<<<'JS'
                    const h = (selector) => Math.round(document.querySelector(selector).getBoundingClientRect().height);
                    return [h('#field-customer'), h('[data-line] input[role=combobox]'), h('[data-form-footer] button[type=submit]'), h('#warehouse-selector')];
                JS)[0];
                $this->assertSame([$height, $height, $height, $height], $sizes, $touch ? 'touch' : 'mouse');

                $browser->visit('/delivery-note')->waitFor('main', 10)->pause(350)->waitUntilMissing('tr[aria-hidden="true"]', 10);
                $row = $browser->script("return Math.round(document.querySelector('table[data-list] tbody tr').getBoundingClientRect().height)")[0];
                $this->assertGreaterThanOrEqual($touch ? 44 : 40, $row, ($touch ? 'touch' : 'mouse').' row');
            }
        });
    }

    public function test_no_text_is_smaller_than_12px(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId);
            $this->screen($browser, 1440, 900);
            $smallest = [];

            foreach (['en' => 12, 'ar' => 13] as $language => $minimum) {
                User::whereKey(self::$userId)->update(['preferred_lang' => $language]);

                foreach (['/delivery-note', '/stock', '/transfer/new', '/items', '/settings/users'] as $path) {
                    $browser->visit($path)->waitFor('main', 10)->pause(350)->waitUntilMissing('tr[aria-hidden="true"]', 10);
                    $smallest["$language $path"] = $browser->script(<<<'JS'
                        let smallest = 99;
                        for (const el of document.querySelectorAll('body *')) {
                            if (! [...el.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim())) continue;
                            if (el.getBoundingClientRect().width === 0 || el.closest('.sr-only')) continue;
                            smallest = Math.min(smallest, parseFloat(getComputedStyle(el).fontSize));
                        }
                        return smallest;
                    JS)[0];
                    $this->assertGreaterThanOrEqual($minimum, $smallest["$language $path"], "$language $path");
                }
            }
        });
    }
}
