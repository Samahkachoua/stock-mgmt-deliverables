<?php

use Illuminate\Support\Facades\Route;

it('shows a translated page for an unknown address, in the browser’s language', function (string $language, string $locale, string $dir) {
    $this->get('/no/such/page', ['Accept-Language' => $language])
        ->assertNotFound()
        ->assertSee('dir="'.$dir.'"', false)
        ->assertSee(__('app.errors.not_found.title', [], $locale))
        ->assertSee(__('app.errors.home', [], $locale));
})->with([
    'English' => ['en-GB,en;q=0.9', 'en', 'ltr'],
    'Arabic' => ['ar-LB,ar;q=0.9', 'ar', 'rtl'],
    'French' => ['fr-FR,fr;q=0.9', 'fr', 'ltr'],
]);

it('shows the user’s language on a server error, without details', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/__boom', fn () => throw new RuntimeException('secret detail'));
    $user = userWithRole('OPERATOR');
    $user->update(['preferred_lang' => 'fr']);

    $this->actingAs($user)->get('/__boom')
        ->assertStatus(500)
        ->assertSee(__('app.errors.server.title', [], 'fr'))
        ->assertDontSee('secret detail')
        ->assertDontSee('RuntimeException');
});

it('renders every error page on its own, in every language', function (int $code) {
    // The app already chose the language (no browser preference to follow).
    request()->headers->remove('Accept-Language');

    foreach (['en', 'ar', 'fr'] as $locale) {
        app()->setLocale($locale);
        $html = view("errors.$code")->render();

        expect($html)->toContain('<html lang="'.$locale.'"')
            ->and($html)->not->toContain('wire:')        // no Livewire
            ->and($html)->not->toContain('/build/');     // no Vite build needed
    }
})->with([404, 419, 429, 500, 503]);
