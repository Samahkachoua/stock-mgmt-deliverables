<?php

use App\Models\User;

it('shows the coming-soon page to everyone at the site root', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(__('welcome.title'))
        ->assertSee(config('app.name'));

    $this->actingAs(User::factory()->create())->get('/')->assertOk();
});

it('shows the coming-soon page in Arabic, right to left', function () {
    $this->withSession(['locale' => 'ar'])->get('/')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('welcome.title', [], 'ar'));
});

it('keeps the WMS under /app', function () {
    expect(route('home', absolute: false))->toBe('/app')
        ->and(route('stock', absolute: false))->toBe('/app/stock')
        ->and(route('login', absolute: false))->toBe('/login');

    $this->get('/app')->assertRedirect(route('login'));
});
