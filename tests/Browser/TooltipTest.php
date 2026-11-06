<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\Stock;

/**
 * Icon-only edit and delete buttons name themselves in a tooltip (resources/js/tooltips.js),
 * and the tooltip goes away when the button opens a form or a dialog.
 */
class TooltipTest extends DuskTestCase
{
    private static bool $seeded = false;

    private static string $userId = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('migrate:fresh', ['--drop-types' => true]);
            self::$userId = Stock::create()->user->id;
            self::$seeded = true;
        }

        User::whereKey(self::$userId)->update(['preferred_lang' => 'en', 'preferred_theme' => 'light']);
    }

    public function test_icon_buttons_show_a_tooltip_on_hover(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs(self::$userId)
                ->resize(1440, 900)
                ->visit('/settings/units')
                ->waitFor('[data-bs-title="'.__('settings.edit').'"]', 10)
                ->mouseover('[data-bs-title="'.__('settings.edit').'"]')
                ->waitFor('.tooltip.show', 5)
                ->assertSeeIn('.tooltip.show', __('settings.edit'));

            if (getenv('DUSK_SCREENSHOTS')) {
                $browser->screenshot('tooltip-edit');
            }

            $browser->mouseover('[data-bs-title="'.__('settings.units.delete').'"]')
                ->waitForTextIn('.tooltip.show', __('settings.units.delete'), 5);

            if (getenv('DUSK_SCREENSHOTS')) {
                $browser->screenshot('tooltip-delete');
            }

            // Opening the edit form leaves no tooltip behind.
            $browser->click('[data-bs-title="'.__('settings.edit').'"]')
                ->pause(800)
                ->assertMissing('.tooltip');
        });
    }
}
