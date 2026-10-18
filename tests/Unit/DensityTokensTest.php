<?php

/*
 * CLAUDE.md "Density": sizes come from the tokens in resources/css/app.css,
 * never from Tailwind's default text scale or hard-coded heights and paddings
 * in a view. The print view, PDF exports and mails keep their own sizes.
 */

function densityCss(): string
{
    return file_get_contents(__DIR__.'/../../resources/css/app.css');
}

/** @return array<string, string> custom property => value, of the block opened by $selector that sets the type scale */
function densityBlock(string $selector): array
{
    preg_match_all('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', densityCss(), $blocks);
    $block = collect($blocks[1])->first(fn (string $body) => str_contains($body, '--fs-body')) ?? '';
    preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/i', $block, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn ($m) => [$m[1] => trim($m[2])])->all();
}

function remToPx(string $value): float
{
    return (float) $value * 16;
}

it('uses only the density tokens in views', function () {
    $root = realpath(__DIR__.'/../../resources/views');
    $old = '/(?<![\w-])(?:[a-z0-9-]+:)*('
        .'text-(?:xs|sm|base|lg|xl|[2-9]xl)'          // text-small, text-body, text-h1 ...
        .'|(?:h|size|min-h)-(?:11|12|16)|min-w-11|h-14'   // h-control, h-row, h-thead, h-topbar
        .'|(?:p|px|py|ps|pe)-6|gap-6|text-\[[^\]]+\]'  // p-card, px-edge, gap-section
        .')(?![\w-])/';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (! str_ends_with($path, '.blade.php') || preg_match('#^(components/layouts/print|exports/|mail/)#', $path)) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match_all($old, $line, $matches)) {
                $found[] = $path.':'.($number + 1).' '.implode(', ', $matches[1]);
            }
        }
    }

    expect($found)->toBe([]);
});

it('never sets text below 12px', function (string $selector) {
    $sizes = collect(densityBlock($selector))->filter(fn ($value, $name) => str_starts_with($name, 'fs-'));

    expect($sizes)->not->toBeEmpty();
    $sizes->each(fn ($value, $name) => expect(remToPx($value))->toBeGreaterThanOrEqual(12.0, "--$name in $selector"));
})->with([':root', "html[lang='ar']", "html.print-view,\nhtml.print-view[lang='ar']"]);

it('makes Arabic one step larger than the other languages', function () {
    $latin = densityBlock(':root');

    foreach (densityBlock("html[lang='ar']") as $name => $value) {
        expect(remToPx($value))->toBeGreaterThan(remToPx($latin[$name]), "--$name");
    }
});

it('has 36px controls with a mouse and 44px on touch screens and phones', function () {
    expect(remToPx(densityBlock(':root')['control']))->toBe(36.0);

    preg_match('/@media \(pointer: coarse\), \(width < 48rem\) \{\s*:root \{(.*?)\}/s', densityCss(), $touch);
    preg_match('/--control:\s*([^;]+);/', $touch[1] ?? '', $control);
    preg_match('/--row:\s*([^;]+);/', $touch[1] ?? '', $row);

    expect(remToPx($control[1] ?? '0'))->toBe(44.0)
        ->and(remToPx($row[1] ?? '0'))->toBeGreaterThanOrEqual(44.0);
});

it('never shrinks the page with zoom or a smaller root font size', function () {
    expect(densityCss())
        ->not->toMatch('/\bzoom\s*:/')
        ->not->toMatch('/^\s*html\s*\{[^}]*font-size/m')
        ->not->toMatch('/:root\s*\{[^}]*font-size/');
});
