<?php

use App\Livewire\ThemeSwitcher;
use Livewire\Livewire;

it('defaults to the system theme', function () {
    $this->actingAs(userWithWarehouses())
        ->get(route('receive'))
        ->assertSee('data-theme="system"', false)
        ->assertDontSee('class="dark"', false);
});

it('saves the chosen theme to the user', function (string $theme) {
    $user = userWithWarehouses();

    Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setTheme', $theme)
        ->assertHasNoErrors();

    expect($user->fresh()->preferred_theme)->toBe($theme);
})->with(['light', 'dark', 'system']);

it('rejects an unknown theme', function () {
    $user = userWithWarehouses();

    Livewire::actingAs($user)
        ->test(ThemeSwitcher::class)
        ->call('setTheme', 'purple')
        ->assertHasErrors('theme');

    expect($user->fresh()->preferred_theme)->toBe('system');
});

it('renders the dark class on the server for a dark-theme user', function () {
    $user = userWithWarehouses();
    $user->update(['preferred_theme' => 'dark']);

    $this->actingAs($user)
        ->get(route('receive'))
        ->assertSee('data-theme="dark"', false)
        ->assertSee('class="dark"', false);
});

it('keeps a guest theme choice in the session', function () {
    Livewire::test(ThemeSwitcher::class)->call('setTheme', 'dark');

    expect(session('theme'))->toBe('dark');
});
