<?php

/*
 * The sidebar's sections (config/ui.php `nav`, App\Support\Navigation\Sidebar).
 */

use App\Models\Warehouse;
use App\Reports\Reports;
use App\Support\Navigation\Sidebar;

it('links Reports to the reports page, not to each report', function () {
    $user = userWithPermissions(Warehouse::factory()->create(), ['stock.view', 'report.stock_card.view', 'report.damage.view']);

    $page = $this->actingAs($user)->get(route('stock'))->assertOk()
        ->assertSee('href="'.route('reports').'"', false);

    foreach (array_keys(Reports::all()) as $key) {
        $page->assertDontSee('href="'.route('reports.show', $key).'"', false);
    }
});

it('keeps Reports marked while a report is open', function () {
    $user = userWithPermissions(Warehouse::factory()->create(), ['stock.view', 'report.stock_card.view']);

    $html = $this->actingAs($user)->get(route('reports.show', 'stock-card'))->assertOk()->getContent();

    expect($html)->toMatch('/href="'.preg_quote(route('reports'), '/').'"[^>]*?aria-current="page"/s');
});

it('links Settings to the settings page, not to each tab', function () {
    $page = $this->actingAs(userWithRole('ADMIN'))->get(route('stock'))->assertOk()
        ->assertSee('href="'.route('settings').'"', false);

    foreach (config('ui.settings_tabs') as $tab) {
        // The tabs themselves are only on the Settings pages.
        $page->assertDontSee('href="'.route($tab['route']).'"', false);
    }
});

it('leaves out sections the user can see nothing of', function () {
    $user = userWithPermissions(Warehouse::factory()->create(), ['stock.view']);

    $labels = array_column(Sidebar::for($user), 'label');

    expect($labels)->toContain(__('nav.sections.inventory'))
        ->not->toContain(__('nav.sections.setup'));
});

it('translates every section heading', function (string $locale) {
    foreach (config('ui.nav') as $section) {
        if ($section['label'] !== null) {
            expect(__($section['label'], [], $locale))->not->toBe($section['label']);
        }
    }
})->with(['en', 'ar', 'fr']);
