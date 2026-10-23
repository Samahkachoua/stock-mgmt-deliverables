<?php

it('redirects guests to the sign in page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([
    'home', 'receive', 'delivery-note', 'transfer', 'adjustment', 'damage',
    'opening-balance', 'stock', 'items', 'settings',
]);

it('sends signed-in users away from the sign in page', function () {
    $this->actingAs(userWithWarehouses())
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

it('signs the user out', function () {
    $this->actingAs(userWithWarehouses())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
