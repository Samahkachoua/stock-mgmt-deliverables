<?php

use App\Support\Palette;

it('reads the light and dark colour tokens from app.css', function () {
    expect(Palette::light())
        ->toHaveKeys(['surface', 'card', 'fg', 'muted', 'border', 'brand', 'on-brand', 'brand-active', 'card-hover', 'subtle', 'status-pending', 'status-completed'])
        ->and(Palette::light()['brand'])->toMatch('/^#[0-9a-f]{6}$/')
        ->and(Palette::dark()['surface'])->not->toBe(Palette::light()['surface']);
});

it('keeps colour values out of views that cannot use the CSS variables', function (string $view) {
    $source = file_get_contents(resource_path("views/{$view}"));

    expect($source)->not->toMatch('/#[0-9a-f]{6}\b/i');
})->with([
    'mail/draft.blade.php',
    'mail/delivery-confirmed.blade.php',
    'exports/report-pdf.blade.php',
    'errors/layout.blade.php',
    'components/favicons.blade.php',
]);
