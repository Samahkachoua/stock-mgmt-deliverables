<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * Settings › Roles in a real browser: one role at a time, its permissions as a
 * tree (section › module › permission) with half-ticked branches, "See" added
 * by any action of its area, search, and the unsaved-changes question.
 */
class RolesTreeTest extends DuskTestCase
{
    private static string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$userId === '') {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);
            self::$userId = Stock::create()->user->id;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
    }

    private function auditor(): Role
    {
        return Role::where('code', 'AUDITOR')->firstOrFail();
    }

    /** The box of a permission (a leaf), by its code. */
    private function leaf(string $code): string
    {
        return 'label[title="'.$code.'"] input';
    }

    /** The box of a branch (section or module), by its node id. */
    private function branch(string $key): string
    {
        return 'li:has(> ul#roles-node-'.$key.') > div input[type=checkbox]';
    }

    private function checked(Browser $browser, string $selector): bool
    {
        return (bool) $browser->script('return document.querySelector('.json_encode($selector).').checked')[0];
    }

    private function indeterminate(Browser $browser, string $selector): bool
    {
        return (bool) $browser->script('return document.querySelector('.json_encode($selector).').indeterminate')[0];
    }

    public function test_ticking_an_action_ticks_see_and_half_ticks_its_branches(): void
    {
        $this->auditor()->permissions()->detach();

        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId)
                ->resize(1440, 900)
                ->script("localStorage.removeItem('roles-tree-open')");

            $browser->visit('/settings/roles')
                ->waitForText(__('settings.roles.sections.inbound'), 10)
                ->press($this->auditor()->label())
                ->waitForText(__('settings.roles.select_all_for', ['role' => $this->auditor()->label()]), 10);

            // Modules start closed: open Draft receipts, tick "Confirm" only.
            $browser->click('button[aria-controls="roles-node-DRAFT_IN"]')
                ->waitFor($this->leaf('draft.in.confirm'), 5)
                ->click($this->leaf('draft.in.confirm'))
                ->pause(200);

            $module = $this->branch('DRAFT_IN');
            $section = $this->branch('section-inbound');

            $this->assertTrue($this->checked($browser, $this->leaf('draft.in.view')), '"See draft receipts" is ticked with Confirm');
            $this->assertTrue($this->indeterminate($browser, $module), 'Draft receipts is half-ticked');
            $this->assertTrue($this->indeterminate($browser, $section), 'Inbound is half-ticked');
            $browser->assertSeeIn('li:has(> ul#roles-node-DRAFT_IN)', __('settings.roles.count', ['granted' => 2, 'total' => 4]));

            // Unticking See unticks Confirm too.
            $browser->click($this->leaf('draft.in.view'))->pause(200);
            $this->assertFalse($this->checked($browser, $this->leaf('draft.in.confirm')));

            // The module box ticks all four, then save.
            $browser->click($module)->pause(200);
            $this->assertTrue($this->checked($browser, $module));
            $this->assertFalse($this->indeterminate($browser, $module));

            $browser->screenshot('roles/en-tree-auditor');

            $browser->press(__('settings.save'))
                ->waitForText(__('settings.roles.saved'), 10);

            $this->assertSame(
                ['draft.in.cancel', 'draft.in.confirm', 'draft.in.create', 'draft.in.view'],
                $this->auditor()->permissions()->orderBy('code')->pluck('code')->all(),
            );
        });
    }

    public function test_search_opens_the_branches_with_a_match(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId)
                ->resize(1440, 900)
                ->visit('/settings/roles')
                ->waitForText(__('settings.roles.sections.inbound'), 10)
                ->type('#roles-search', 'driver')
                ->pause(300)
                ->assertVisible($this->leaf('driver.delete'))
                ->assertMissing($this->leaf('stock.in.create'))
                ->type('#roles-search', 'zzzz')
                ->pause(300)
                ->assertSee(__('settings.roles.no_results', ['search' => 'zzzz']));
        });
    }

    public function test_leaving_with_unsaved_ticks_asks_first(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId)
                ->resize(1440, 900)
                ->visit('/settings/roles')
                ->waitForText(__('settings.roles.sections.inbound'), 10)
                ->press($this->auditor()->label())
                ->waitForText(__('settings.roles.select_all_for', ['role' => $this->auditor()->label()]), 10)
                // Sections start open.
                ->waitFor($this->leaf('report.expiry.view'), 5)
                ->click($this->leaf('report.expiry.view'))
                ->pause(200)
                // Switching role keeps the change, marked "not saved".
                ->script('window.scrollTo(0, 0)');

            $browser
                ->press(__('roles.ADMIN'))
                ->waitFor('[title="'.__('settings.roles.unsaved').'"]', 10)
                ->clickLink(__('settings.tabs.users'))
                ->waitFor('[data-confirm-dialog]', 10)
                ->assertSee(__('confirm.discard.roles.title'))
                ->press(__('confirm.keep'))
                ->waitUntilMissing('[data-confirm-dialog]', 10)
                ->assertPathIs('/settings/roles');
        });
    }
}
