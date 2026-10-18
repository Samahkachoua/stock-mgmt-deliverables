<?php

it('renders English left-to-right by default', function () {
    $this->actingAs(userWithWarehouses())
        ->get(route('receive'))
        ->assertOk()
        ->assertSee('lang="en"', false)
        ->assertSee('dir="ltr"', false);
});

it('renders Arabic right-to-left for a user who prefers Arabic', function () {
    $user = userWithWarehouses();
    $user->update(['preferred_lang' => 'ar']);

    $this->actingAs($user)
        ->get(route('receive'))
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('nav.receive', [], 'ar'));
});

it('saves the language to the user and reloads the page', function () {
    $user = userWithWarehouses();

    $this->actingAs($user)
        ->from(route('stock'))
        ->post(route('locale.update', 'fr'))
        ->assertRedirect(route('stock'));

    expect($user->fresh()->preferred_lang)->toBe('fr');
});

it('keeps a guest language choice in the session', function () {
    $this->from(route('login'))
        ->post(route('locale.update', 'ar'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'ar');

    $this->get(route('login'))
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('auth.title', [], 'ar'));
});

it('rejects an unsupported language', function () {
    $this->post(route('locale.update', 'de'))->assertNotFound();
});
