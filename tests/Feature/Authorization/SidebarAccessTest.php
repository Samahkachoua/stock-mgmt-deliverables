<?php

/*
 * Every role against every sidebar area and settings tab: what it may open,
 * and that the sidebar shows exactly those entries.
 */

const STOCK_AREAS = ['receive', 'issue', 'transfer', 'adjustment', 'damage', 'opening-balance', 'stock'];

/**
 * Areas each seeded role may open (besides the stock pages, which every role sees).
 *
 * @return array<string, list<string>>
 */
function roleAreas(): array
{
    return [
        'ADMIN' => ['items', 'settings', 'settings.warehouses', 'settings.approval-limits', 'settings.users', 'settings.roles'],
        'MANAGER' => ['items', 'settings', 'settings.warehouses'],
        'OPERATOR' => [],
        'DATA_ENTRY' => ['items', 'settings', 'settings.warehouses'],
        'AUDITOR' => [],
        'CUSTOMER' => [],
    ];
}

dataset('role areas', function () {
    $areas = [...STOCK_AREAS, 'items', 'settings', 'settings.warehouses', 'settings.approval-limits', 'settings.users', 'settings.roles'];

    foreach (roleAreas() as $role => $extra) {
        foreach ($areas as $area) {
            yield "$role → $area" => [$role, $area, in_array($area, STOCK_AREAS, true) || in_array($area, $extra, true)];
        }
    }
});

it('lets each role open only its areas', function (string $role, string $area, bool $allowed) {
    $response = $this->actingAs(userWithRole($role))->get(route($area));

    if (! $allowed) {
        $response->assertForbidden();
    } elseif ($area === 'settings') {
        $response->assertRedirect(); // to the first tab the role may open
    } else {
        $response->assertOk();
    }
})->with('role areas');

it('shows each role only the sidebar entries it may open', function (string $role, array $extra) {
    $page = $this->actingAs(userWithRole($role))->get(route('receive'))->assertOk();

    foreach ([...STOCK_AREAS, 'items', 'settings'] as $area) {
        $visible = in_array($area, STOCK_AREAS, true) || in_array($area, $extra, true);
        $link = 'href="'.route($area).'"';

        $visible ? $page->assertSee($link, false) : $page->assertDontSee($link, false);
    }
})->with(fn () => collect(roleAreas())->map(fn ($extra, $role) => [$role, $extra])->all());
