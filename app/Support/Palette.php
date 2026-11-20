<?php

namespace App\Support;

use RuntimeException;

/**
 * The colour tokens of resources/css/app.css as hex values, for the pages that
 * can't use the CSS variables: emails (mail clients ignore them), mPDF exports
 * and the error page (it loads no build). app.css stays the only place colour
 * values are written: Palette::light()['muted'] is the light theme's --muted.
 */
final class Palette
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** @return array<string, string> token (without "--") => #rrggbb */
    public static function light(): array
    {
        return self::block(':root');
    }

    /** @return array<string, string> token (without "--") => #rrggbb */
    public static function dark(): array
    {
        return self::block('.dark');
    }

    /** @return array<string, string> */
    private static function block(string $selector): array
    {
        if (isset(self::$cache[$selector])) {
            return self::$cache[$selector];
        }

        $css = (string) file_get_contents(resource_path('css/app.css'));

        // The first block with this selector is the colour block (the density block comes later).
        if (! preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block)) {
            throw new RuntimeException("No {$selector} block in app.css");
        }

        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-f]{6})\s*;/i', $block[1], $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as [, $name, $hex]) {
            $tokens[$name] = strtolower($hex);
        }

        return self::$cache[$selector] = $tokens;
    }
}
