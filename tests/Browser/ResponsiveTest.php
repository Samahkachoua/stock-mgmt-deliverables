<?php

namespace Tests\Browser;

use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * CLAUDE.md "Responsive layout", in a real browser at 375x667 (phone),
 * 768x1024 (tablet) and 1440x900 (desktop): no sideways page scrolling, the
 * sidebar mode of each size, filters of equal width, lists as cards on a
 * phone, and a transaction entered from start to finish on a phone.
 */
class ResponsiveTest extends DuskTestCase
{
    private static bool $seeded = false;

    private static string $userId = '';

    private static string $warehouseId = '';

    private static string $itemId = '';

    private static string $reference = '';

    private static string $draft = '';

    protected function setUp(): void
    {
        parent::setUp();

        // One set of data for the whole class, committed so the app under test sees it.
        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);
            // The app under test caches the permission map in its file cache (DuskTestCase):
            // one left from an earlier run would miss permissions added since.
            Cache::store('file')->flush();

            $stock = Stock::create();
            Customer::factory()->create(['code' => 'CEDAR', 'name' => 'Cedar Trading']);
            $tripoli = Warehouse::factory()->create(['code' => 'TRP', 'name' => 'Tripoli']);
            $stock->user->warehouses()->attach($tripoli);

            for ($i = 0; $i < 12; $i++) {
                $receipt = $stock->post('IN', [$stock->line('BEANS', 24), $stock->line('YOGURT', 6, expiry: now()->addMonths(6)->addDays($i)->toDateString())]);
            }

            $stock->post('OUT', [$stock->line('BEANS', 12)]);
            $stock->dispatch([$stock->line('BEANS', 12)], $tripoli);

            self::$userId = $stock->user->id;
            self::$warehouseId = $stock->warehouse->id;
            self::$itemId = $stock->id('BEANS');
            self::$reference = $receipt->doc_no;

            // An order of two lines (the user has two warehouses: the form shows the Warehouse field).
            $branch = CustomerBranch::create(['customer_id' => $stock->customer->id, 'code' => 'DT', 'name' => 'Downtown']);
            self::$draft = app(DraftService::class)->save(new DraftInput('OUT', $stock->customer->id, $branch->id, $stock->warehouse->id, CarbonImmutable::today(), 'PO 77', [
                'r1' => new DraftLineInput($stock->id('BEANS'), 'BOX', 2),
                'r2' => new DraftLineInput($stock->id('BEANS'), 'PC', 5),
            ]), $stock->user)->doc_no;

            self::$seeded = true;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sizes(): array
    {
        return ['phone' => ['phone'], 'tablet' => ['tablet'], 'desktop' => ['desktop']];
    }

    /**
     * Every page of the app, by a short name.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        $pages = ['home' => '/', 'dashboard' => '/dashboard'];

        foreach (['receive', 'delivery-note', 'transfer', 'adjustment', 'damage', 'opening-balance'] as $slug) {
            $pages[$slug.' list'] = '/'.$slug;
            $pages[$slug.' form'] = '/'.$slug.'/new';
        }

        return $pages + [
            'receive details' => '/receive/'.self::$reference,
            'draft receipts' => '/draft-receipts',
            'draft receipt form' => '/draft-receipts/new',
            'orders' => '/orders',
            'order details' => '/orders/'.self::$draft,
            'order edit' => '/orders/'.self::$draft.'/edit',
            'branches' => '/settings/branches',
            'stock' => '/stock',
            'item stock' => '/stock/'.self::$itemId.'/'.self::$warehouseId,
            'items' => '/items',
            'item form' => '/items/new',
            'item edit' => '/items/'.self::$itemId.'/edit',
            'settings' => '/settings',
            'warehouses' => '/settings/warehouses',
            'customers' => '/settings/customers',
            'units' => '/settings/units',
            'users' => '/settings/users',
            'roles' => '/settings/roles',
            'approval limits' => '/settings/approval-limits',
            'reports' => '/reports',
            'stock card' => '/reports/stock-card',
            'expiry report' => '/reports/expiry',
            'dormant stock' => '/reports/dormant-stock',
            'damage report' => '/reports/damage',
            'adjustments report' => '/reports/adjustments',
            'transfers in transit' => '/reports/transfers-in-transit',
            'transfer shortages' => '/reports/transfer-shortages',
            'audit log' => '/reports/audit-log',
            'imports' => '/imports',
            'import upload' => '/imports/new/items',
        ];
    }

    /**
     * How far the page can be scrolled sideways, and what sticks out (for the failure message).
     *
     * @return array{overflow: int, culprits: list<string>}
     */
    private function sideways(Browser $browser): array
    {
        return $browser->script(<<<'JS'
            const width = document.documentElement.clientWidth;
            const culprits = [];
            for (const el of document.querySelectorAll('body *')) {
                const box = el.getBoundingClientRect();
                if (box.width === 0 || box.height === 0) continue;
                if (el.closest('#nprogress')) continue; // wire:navigate's progress bar, sliding in
                if (getComputedStyle(el).visibility === 'hidden') continue;
                if (box.right > width + 1 || box.left < -1) {
                    // Inside a region that scrolls sideways on its own, or off-canvas on purpose: fine.
                    let scroller = el.parentElement, own = false;
                    while (scroller && scroller !== document.body) {
                        const style = getComputedStyle(scroller);
                        if (['auto', 'scroll', 'hidden', 'clip'].includes(style.overflowX)) { own = true; break; }
                        scroller = scroller.parentElement;
                    }
                    if (! own && culprits.length < 5) culprits.push(el.tagName.toLowerCase() + '.' + String(el.className).slice(0, 80));
                }
            }
            return { overflow: document.documentElement.scrollWidth - width, culprits };
        JS)[0];
    }

    private function open(Browser $browser, string $size, string $path): Browser
    {
        $this->viewport($browser, $size)->visit($path)->waitFor('main', 10);
        // Lists (and the dashboard) fetch their rows right after the first paint.
        $browser->pause(350)->waitUntilMissing('tr[aria-hidden="true"]', 10)->waitUntilMissing('[aria-busy="true"]', 10);

        return $browser;
    }

    #[DataProvider('sizes')]
    public function test_no_page_scrolls_sideways(string $size): void
    {
        $this->browse(function (Browser $browser) use ($size) {
            $browser->loginAs(self::$userId);
            $problems = [];

            foreach ([['en', 'light'], ['ar', 'dark']] as [$language, $theme]) {
                User::whereKey(self::$userId)->update(['preferred_lang' => $language, 'preferred_theme' => $theme]);

                foreach ($this->pages() as $name => $path) {
                    $result = $this->sideways($this->open($browser, $size, $path));

                    if ($result['overflow'] > 1 || $result['culprits'] !== []) {
                        $problems[] = "$name ($language, $theme): +{$result['overflow']}px ".implode(' | ', $result['culprits']);
                    }

                    if (getenv('DUSK_SCREENSHOTS')) {
                        $browser->screenshot($size.'/'.$language.'-'.str_replace(' ', '-', $name));
                    }
                }
            }

            $this->assertSame([], $problems, "Pages that scroll sideways at $size");
        });
    }

    public function test_login_page_fits_every_size(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->logout();

            foreach (array_keys(self::SIZES) as $size) {
                $this->viewport($browser, $size)->visit('/login')->waitFor('form');
                $result = $this->sideways($browser);

                $this->assertLessThanOrEqual(1, $result['overflow'], "Login at $size");
                $this->assertSame([], $result['culprits'], "Login at $size");
            }
        });
    }

    #[DataProvider('sizes')]
    public function test_sidebar_mode(string $size): void
    {
        $this->browse(function (Browser $browser) use ($size) {
            $this->open($browser->loginAs(self::$userId), $size, '/receive');

            $sidebar = fn () => $browser->script(<<<'JS'
                const aside = document.querySelector('[data-sidebar]');
                const box = aside.getBoundingClientRect();
                const label = aside.querySelector('nav a span');
                return {
                    width: Math.round(box.width),
                    onScreen: getComputedStyle(aside).visibility !== 'hidden' && box.right > 0 && box.left < window.innerWidth,
                    labels: label.getBoundingClientRect().width > 2,
                    menuButton: getComputedStyle(document.querySelector('[data-menu-button]')).display !== 'none',
                };
            JS)[0];

            $state = $sidebar();

            match ($size) {
                // Full sidebar with labels.
                'desktop' => $this->assertEquals(['width' => 224, 'onScreen' => true, 'labels' => true, 'menuButton' => false], $state),
                // Icons only; the labels are tooltips.
                'tablet' => $this->assertEquals(['width' => 64, 'onScreen' => true, 'labels' => false, 'menuButton' => false], $state),
                // Hidden; the menu button opens it as a drawer.
                'phone' => $this->assertEquals(['onScreen' => false, 'menuButton' => true], ['onScreen' => $state['onScreen'], 'menuButton' => $state['menuButton']]),
            };

            // Icons only: hovering or focusing one shows its label beside the rail, level with it.
            $tip = fn () => $browser->script(<<<'JS'
                const tip = document.querySelector('[data-sidebar-tip]');
                const box = tip.getBoundingClientRect();
                const link = document.querySelector('[data-sidebar] a[href$="/delivery-note"]').getBoundingClientRect();
                return {
                    shown: getComputedStyle(tip).display !== 'none' && box.width > 0,
                    text: tip.textContent.trim(),
                    besideRail: document.dir === 'rtl'
                        ? box.right <= document.querySelector('[data-sidebar]').getBoundingClientRect().left
                        : box.left >= document.querySelector('[data-sidebar]').getBoundingClientRect().right,
                    level: Math.abs((box.top + box.height / 2) - (link.top + link.height / 2)) < 2,
                };
            JS)[0];

            if ($size !== 'phone') {
                $browser->mouseover('[data-sidebar] a[href$="/delivery-note"]')->pause(100);
                $hovered = $tip();
                $browser->mouseover('main')->pause(100);

                if ($size === 'tablet') {
                    $this->assertEquals(['shown' => true, 'text' => __('transactions.types.delivery_note.title'), 'besideRail' => true, 'level' => true], $hovered);
                    $this->assertFalse($tip()['shown']);

                    $browser->script("document.querySelector('[data-sidebar] a[href$=\"/delivery-note\"]').focus()");
                    $this->assertTrue($tip()['shown']);

                    // Arabic: the rail is on the right, the label to its left.
                    User::whereKey(self::$userId)->update(['preferred_lang' => 'ar', 'preferred_theme' => 'dark']);
                    $this->open($browser, $size, '/receive')->mouseover('[data-sidebar] a[href$="/delivery-note"]')->pause(100);
                    app()->setLocale('ar');
                    $this->assertEquals(['shown' => true, 'text' => __('transactions.types.delivery_note.title'), 'besideRail' => true, 'level' => true], $tip());
                    app()->setLocale('en');

                    if (getenv('DUSK_SCREENSHOTS')) {
                        $browser->screenshot('tablet/ar-sidebar-tooltip');
                    }
                    User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
                } else {
                    // The labels are visible already.
                    $this->assertFalse($hovered['shown']);
                }
            }

            if ($size === 'phone') {
                $browser->click('[data-menu-button]')->pause(350);
                $open = $sidebar();
                $this->assertTrue($open['onScreen']);
                $this->assertTrue($open['labels']);

                // It closes on navigation.
                $browser->click('[data-sidebar] a[href$="/delivery-note"]')->waitForLocation('/delivery-note')->pause(350);
                $this->assertFalse($sidebar()['onScreen']);

                // Theme and language are in the user menu.
                $browser->click('button[aria-haspopup="menu"]')->waitFor('[data-menu-settings]');
                $this->assertTrue($browser->script("return document.querySelector('[data-menu-settings]').getBoundingClientRect().width > 0")[0]);
            }
        });
    }

    #[DataProvider('sizes')]
    public function test_filters_bar(string $size): void
    {
        $this->browse(function (Browser $browser) use ($size) {
            foreach (['/transfer', '/reports/damage'] as $path) {
                $this->open($browser->loginAs(self::$userId), $size, $path);

                if ($size === 'phone') {
                    // Search stays; the other filters are behind "Filters".
                    $browser->assertVisible('[data-filters-bar] input[type="search"]')
                        ->assertVisible('[data-filters-button]')
                        ->assertMissing('[data-filters-grid]')
                        ->click('[data-filters-button]')
                        ->waitFor('[data-filters-grid]')
                        ->assertVisible('[data-filters-apply]');

                    $widths = $browser->script("return [...document.querySelectorAll('[data-filters-grid] > *')].map((el) => Math.round(el.getBoundingClientRect().width))")[0];
                    $this->assertCount(1, array_unique($widths), "Stacked full width in the panel on $path");

                    $browser->click('[data-filters-apply]')->waitUntilMissing('[data-filters-grid]');

                    continue;
                }

                $browser->assertMissing('[data-filters-button]');
                $boxes = $browser->script("return [...document.querySelectorAll('[data-filters-grid] > *')].map((el) => { const box = el.getBoundingClientRect(); return { width: Math.round(box.width), top: Math.round(box.bottom) }; })")[0];

                $this->assertGreaterThanOrEqual(4, count($boxes), $path);
                $this->assertLessThanOrEqual(1, max(array_column($boxes, 'width')) - min(array_column($boxes, 'width')), "Filters of equal width at $size on $path");

                // 4 per row on desktop, 2 on tablet.
                $firstRow = count(array_filter($boxes, fn (array $box) => abs($box['top'] - $boxes[0]['top']) <= 1));
                $this->assertSame($size === 'desktop' ? 4 : 2, $firstRow, "Filters per row at $size on $path");
            }
        });
    }

    public function test_filter_count_on_the_phone_button(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser->loginAs(self::$userId), 'phone', '/receive');
            $browser->click('[data-filters-button]')->waitFor('[data-filters-grid]')
                ->select('#filter-status', 'POSTED')
                ->click('[data-filters-apply]')
                ->waitForTextIn('[data-filters-button]', '1');

            $this->assertStringContainsString('1', $browser->text('[data-filters-button]'));

            // "Clear filters" in the panel empties them again.
            $browser->click('[data-filters-button]')->waitFor('[data-filters-grid]')
                ->press(__('transactions.filters.reset'))
                ->waitUntilMissing('[data-filters-grid]')
                ->waitUsing(10, 100, fn () => trim($browser->text('[data-filters-button]')) === __('transactions.filters.filters'));

            $this->assertSame('', $browser->value('#filter-status'));
        });
    }

    #[DataProvider('sizes')]
    public function test_lists_are_cards_on_a_phone_and_tables_elsewhere(string $size): void
    {
        $this->browse(function (Browser $browser) use ($size) {
            foreach (['/receive', '/reports/damage', '/items'] as $path) {
                $this->open($browser->loginAs(self::$userId), $size, $path);

                if ($browser->element('table[data-list]') === null) {
                    continue; // nothing to list (an empty report)
                }

                $layout = $browser->script(<<<'JS'
                    const table = document.querySelector('table[data-list]');
                    const row = table.querySelector('tbody tr');
                    const hidden = [...table.querySelectorAll('thead th[data-priority]')].filter((th) => getComputedStyle(th).display === 'none').length;
                    return {
                        table: getComputedStyle(table).display,
                        head: getComputedStyle(table.querySelector('thead')).display,
                        row: getComputedStyle(row).display,
                        hiddenColumns: hidden,
                        lowPriority: table.querySelectorAll('thead th[data-priority]').length,
                    };
                JS)[0];

                if ($size === 'phone') {
                    $this->assertSame(['block', 'none', 'flex'], [$layout['table'], $layout['head'], $layout['row']], "Cards on a phone on $path");
                } else {
                    $this->assertSame(['table', 'table-header-group', 'table-row'], [$layout['table'], $layout['head'], $layout['row']], "A table at $size on $path");
                    // Tablet: the low-priority columns are gone; desktop: all are there.
                    $this->assertGreaterThan(0, $layout['lowPriority'], $path);
                    $this->assertSame($size === 'tablet' ? $layout['lowPriority'] : 0, $layout['hiddenColumns'], "Low-priority columns at $size on $path");
                }
            }

            // Pagination: "Previous / Next" with "Page 1 of 2" on a phone, page numbers elsewhere.
            $this->open($browser, $size, '/receive');
            $size === 'phone' ? $browser->assertVisible('[data-page-of]') : $browser->assertMissing('[data-page-of]');
        });
    }

    public function test_a_card_opens_its_document_on_a_phone(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser->loginAs(self::$userId), 'phone', '/receive');
            $reference = trim($browser->text('table[data-list] tbody tr:first-child [data-card="title"]'));

            // Anywhere on the card: its link covers all of it.
            $covered = $browser->script(<<<'JS'
                const row = document.querySelector('table[data-list] tbody tr');
                const box = row.getBoundingClientRect();
                const hit = (x, y) => document.elementFromPoint(x, y)?.closest('a') === row.querySelector('a');
                return hit(box.left + 20, box.bottom - 12) && hit(box.right - 20, box.top + 12) && hit(box.left + box.width / 2, box.top + box.height / 2);
            JS)[0];
            $this->assertTrue($covered);

            $browser->click('table[data-list] tbody tr:first-child a')->waitForLocation('/receive/'.$reference);
            $this->assertSame([], $this->sideways($browser)['culprits']);
        });
    }

    public function test_dates_in_the_wrong_order_are_reported_at_once(): void
    {
        $this->browse(function (Browser $browser) {
            $this->open($browser->loginAs(self::$userId), 'desktop', '/delivery-note');

            // Typed the way a user does: the pickers fire `change`.
            $set = fn (string $id, string $day) => $browser->script("const input = document.getElementById('$id'); input.value = '$day'; input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true }));");
            $set('filter-dateFrom', '2026-09-20');
            $browser->pause(600);
            $set('filter-dateTo', '2026-09-10');

            // Next to the dates, in the list, and as a toast: before anything is cleared.
            $browser->waitFor('[data-date-error]', 5)
                ->waitFor('[data-toast="error"]', 5)
                ->assertSeeIn('[data-date-error]', __('feedback.date_order'))
                ->assertSeeIn('[data-toast="error"]', __('feedback.date_order'))
                ->assertDontSee(__('transactions.list.no_matches_title'))
                ->screenshot('date-order');
        });
    }

    #[DataProvider('sizes')]
    public function test_the_item_picker_shows_whole_item_names(string $size): void
    {
        $customer = Customer::where('code', 'ACME')->value('id');

        $this->browse(function (Browser $browser) use ($customer, $size) {
            $this->open($browser->loginAs(self::$userId), $size, '/receive/new');

            $browser->select('#field-customer', (string) $customer)
                ->waitUntilEnabled('[data-line] input[role="combobox"]');
            $browser->script("document.querySelector('[data-line] input[role=combobox]').scrollIntoView({ block: 'center' })");
            $browser->click('[data-line] input[role="combobox"]')
                ->waitFor('[data-line] [role="option"]')
                ->pause(500);

            // No name is cut off, and the list stays on the screen.
            $list = $browser->script(<<<'JS'
                const names = [...document.querySelectorAll('[data-line] [role="option"] bdi')];
                const box = document.querySelector('[data-line] [role="listbox"]').parentElement.getBoundingClientRect();
                return {
                    options: names.length > 0,
                    cut: names.filter((name) => name.scrollWidth > name.clientWidth + 1 || getComputedStyle(name).textOverflow === 'ellipsis').length,
                    onScreen: box.left >= 0 && box.right <= window.innerWidth + 1,
                    box: [Math.round(box.left), Math.round(box.right), window.innerWidth],
                };
            JS)[0];
            $box = implode(' / ', $list['box']);
            unset($list['box']);
            $this->assertEquals(['options' => true, 'cut' => 0, 'onScreen' => true], $list, "Item picker list at $size (left / right / screen: $box)");
            $browser->screenshot("item-picker-$size");

            $browser->click('[data-line] [role="option"]')
                ->waitUntilEnabled('[data-line] input[inputmode="numeric"]');

            // The chosen item too.
            $chosen = $browser->script(<<<'JS'
                const name = document.querySelector('[data-line] td[data-label] button bdi');
                return name.scrollWidth <= name.clientWidth + 1 && getComputedStyle(name).textOverflow !== 'ellipsis';
            JS)[0];
            $this->assertTrue($chosen, "Chosen item name at $size");
        });
    }

    public function test_a_receipt_can_be_entered_on_a_phone(): void
    {
        $before = (float) DB::table('stock_balance')->where('product_id', self::$itemId)->where('warehouse_id', self::$warehouseId)->sum('qty_base');
        $customer = Customer::where('code', 'ACME')->value('id');

        $this->browse(function (Browser $browser) use ($customer) {
            $this->open($browser->loginAs(self::$userId), 'phone', '/receive/new');

            $browser->select('#field-customer', (string) $customer)
                ->waitUntilEnabled('[data-line] input[role="combobox"]');
            $browser->script("document.querySelector('[data-line] input[role=combobox]').scrollIntoView({ block: 'center' })");
            $browser->click('[data-line] input[role="combobox"]')
                // The item picker is a full-screen search panel.
                ->waitFor('[data-picker-panel]');

            $panel = $browser->script("const box = document.querySelector('[data-picker-panel]').getBoundingClientRect(); return [Math.round(box.width), Math.round(box.height), Math.round(box.left), Math.round(box.top)];")[0];
            $this->assertSame([375, 667, 0, 0], $panel);

            $browser->type('[data-picker-panel] input[role="combobox"]', 'beans')
                ->waitFor('[data-picker-panel] [role="option"]')
                ->pause(500)
                ->click('[data-picker-panel] [role="option"]')
                ->waitUntilMissing('[data-picker-panel]')
                ->waitUntilEnabled('[data-line] input[inputmode="numeric"]');

            // Each line is a card: Unit and Quantity side by side under the item.
            $line = $browser->script(<<<'JS'
                const row = document.querySelector('[data-line]');
                const unit = row.querySelector('select').closest('td').getBoundingClientRect();
                const quantity = row.querySelector('input[inputmode="numeric"]').closest('td').getBoundingClientRect();
                const item = row.querySelector('td[data-label]').getBoundingClientRect();
                return { row: getComputedStyle(row).display, sideBySide: Math.abs(unit.top - quantity.top) < 2, itemAbove: item.bottom <= unit.top + 1, itemWide: item.width > 300 };
            JS)[0];
            $this->assertEquals(['row' => 'flex', 'sideBySide' => true, 'itemAbove' => true, 'itemWide' => true], $line);

            $browser->script("document.querySelector('[data-line] input[inputmode=numeric]').scrollIntoView({ block: 'center' })");
            $browser->type('[data-line] input[inputmode="numeric"]', '5')
                ->waitUntilEnabled('[data-form-footer] button[type="submit"]');

            // Sticky footer: Save below the summary.
            $footer = $browser->script(<<<'JS'
                const footer = document.querySelector('[data-form-footer]');
                const save = footer.querySelector('button[type="submit"]').getBoundingClientRect();
                const summary = footer.querySelector('p').getBoundingClientRect();
                return { below: save.top >= summary.bottom - 1, inView: save.bottom <= window.innerHeight + 1, tall: save.height >= 44 };
            JS)[0];
            $this->assertEquals(['below' => true, 'inView' => true, 'tall' => true], $footer);
            $this->assertSame([], $this->sideways($browser)['culprits']);

            $browser->click('[data-form-footer] button[type="submit"]')
                ->waitUsing(15, 100, fn () => (bool) preg_match('#/receive/REC-#', (string) $browser->driver->getCurrentURL()))
                // The success toast spans the top of the phone.
                ->waitFor('[data-toast="success"]');

            $toast = $browser->script("const box = document.querySelector('[data-toast]').getBoundingClientRect(); return [Math.round(box.left), Math.round(box.width)];")[0];
            $this->assertLessThanOrEqual(16, $toast[0]);
            $this->assertGreaterThanOrEqual(340, $toast[1]);
        });

        $after = (float) DB::table('stock_balance')->where('product_id', self::$itemId)->where('warehouse_id', self::$warehouseId)->sum('qty_base');
        $this->assertSame($before + 5.0, $after);
        $this->assertSame('POSTED', StockTransaction::withoutGlobalScopes()->where('transaction_type', 'IN')->latest('id')->value('status'));
    }

    public function test_the_confirmation_is_a_bottom_sheet_on_a_phone(): void
    {
        $this->browse(function (Browser $browser) {
            foreach (['phone', 'desktop'] as $size) {
                $this->open($browser->loginAs(self::$userId), $size, '/receive/'.self::$reference);
                $browser->press(__('stock.cancel.button'))->waitFor('[data-confirm-dialog]');

                $sheet = $browser->script("const box = document.querySelector('[data-confirm-dialog]').getBoundingClientRect(); return { width: Math.round(box.width), atBottom: Math.abs(box.bottom - window.innerHeight) < 2 };")[0];

                $size === 'phone'
                    ? $this->assertEquals(['width' => 375, 'atBottom' => true], $sheet)
                    : $this->assertFalse($sheet['atBottom']);

                $browser->press(__('confirm.keep'))->waitUntilMissing('[data-confirm-dialog]');
            }
        });
    }
}
