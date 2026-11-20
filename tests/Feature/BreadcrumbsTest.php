<?php

/*
 * The breadcrumb above every page title (x-breadcrumbs, x-page-header, x-settings.layout).
 */

use Tests\Support\Stock;

function breadcrumbs(string $html): string
{
    preg_match('/<nav[^>]*data-breadcrumbs.*?<\/nav>/s', $html, $match);

    return $match[0] ?? '';
}

it('shows Home › list › reference on a details page', function () {
    $stock = Stock::create();
    $doc = $stock->post('IN', [$stock->line('BEANS', 5)]);

    $trail = breadcrumbs($this->actingAs($stock->user)->get(route('receive.show', $doc->doc_no))->assertOk()->getContent());

    expect($trail)->toContain('href="'.route('home').'"')
        ->toContain('href="'.route('receive').'"')
        ->toMatch('/aria-current="page"[^>]*>'.preg_quote($doc->doc_no, '/').'</');
});

it('shows Home › list on a list page, with the list as the current page', function () {
    $stock = Stock::create();

    $trail = breadcrumbs($this->actingAs($stock->user)->get(route('receive'))->assertOk()->getContent());

    expect($trail)->toContain('href="'.route('home').'"')
        ->not->toContain('href="'.route('receive').'"')
        ->toContain(__('transactions.types.receive.title'));
});

it('shows Home › Settings › the open tab in settings', function () {
    $stock = Stock::create();

    $trail = breadcrumbs($this->actingAs($stock->user)->get(route('settings.units'))->assertOk()->getContent());

    expect($trail)->toContain('href="'.route('settings').'"')
        ->toMatch('/aria-current="page"[^>]*>'.preg_quote(__('settings.tabs.units'), '/').'</');
});

it('has no breadcrumb on Home', function () {
    $stock = Stock::create();

    expect(breadcrumbs($this->actingAs($stock->user)->get(route('home'))->assertOk()->getContent()))->toBe('');
});
