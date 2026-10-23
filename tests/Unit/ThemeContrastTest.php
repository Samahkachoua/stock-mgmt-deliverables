<?php

/*
 * CLAUDE.md: text >= 4.5:1, icons and control borders >= 3:1, in both themes.
 * Reads the colour tokens straight from resources/css/app.css.
 */

function themeTokens(string $selector): array
{
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block);
    preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all();
}

function relativeLuminance(string $hex): float
{
    $channels = array_map(function (string $pair) {
        $c = hexdec($pair) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $a, string $b): float
{
    [$light, $dark] = [max(relativeLuminance($a), relativeLuminance($b)), min(relativeLuminance($a), relativeLuminance($b))];

    return ($light + 0.05) / ($dark + 0.05);
}

/** @return array<int, array{string, string, float}> [foreground, background, minimum] */
function contrastPairs(): array
{
    $pairs = [
        ['fg', 'surface', 4.5], ['fg', 'card', 4.5], ['fg', 'card-hover', 4.5],
        ['muted', 'surface', 4.5], ['muted', 'card', 4.5], ['muted', 'card-hover', 4.5],
        ['on-brand', 'brand', 4.5], ['on-brand', 'brand-hover', 4.5],
        ['danger', 'card', 4.5], ['danger', 'danger-soft', 4.5], ['danger', 'surface', 4.5],
        ['border-control', 'card', 3.0], ['border-control', 'surface', 3.0],
        ['brand', 'card', 3.0],
    ];

    foreach (['receive', 'delivery', 'transfer', 'adjustment', 'damage', 'opening'] as $type) {
        $pairs[] = ["type-$type", 'card', 3.0];                 // sidebar / header icons
        $pairs[] = ['fg', "type-$type-soft", 4.5];              // active nav label
        $pairs[] = ['on-brand', "type-$type-strong", 4.5];      // filled type buttons
    }

    foreach (['pending', 'transit', 'completed', 'rejected', 'cancelled'] as $status) {
        $pairs[] = ["status-$status", "status-$status-soft", 4.5];   // badge text
        $pairs[] = ["status-$status", 'card', 4.5];
        $pairs[] = ["status-$status", 'surface', 4.5];    // badges and amounts in a hovered table row
    }

    return $pairs;
}

it('meets the contrast minimums', function (string $selector) {
    $tokens = themeTokens($selector);

    expect($tokens)->not->toBeEmpty();

    $failures = [];

    foreach (contrastPairs() as [$fg, $bg, $minimum]) {
        expect($tokens)->toHaveKeys([$fg, $bg]);

        $ratio = contrastRatio($tokens[$fg], $tokens[$bg]);

        if ($ratio < $minimum) {
            $failures[] = sprintf('%s on %s: %.2f (needs %.1f)', $fg, $bg, $ratio, $minimum);
        }
    }

    expect($failures)->toBe([], "[$selector] ".implode('; ', $failures));
})->with([
    'light' => ':root',
    'dark' => '.dark',
]);

it('defines the same tokens in light and dark', function () {
    expect(array_keys(themeTokens('.dark')))->toEqualCanonicalizing(array_keys(themeTokens(':root')));
});
