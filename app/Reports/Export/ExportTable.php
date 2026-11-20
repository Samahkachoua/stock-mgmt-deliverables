<?php

namespace App\Reports\Export;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A list as it is exported (Excel / PDF): a title, its columns and its rows as
 * the text the screen shows. Lists build it from their own rendered table
 * (fromHtml), so an export always says what the list says, column for column.
 */
final class ExportTable
{
    /**
     * @param  array<string, array{label: string, align?: string}>  $columns  keyed like the list's columns
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $filters  lines describing the filters in use
     */
    public function __construct(
        public readonly string $title,
        public readonly string $slug,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $filters = [],
    ) {}

    /**
     * Reads the list's table (`table[data-list]`, x-data-table): every header
     * and cell with a `data-col`, minus the hidden columns. Screen-reader-only
     * text is left out, white space is collapsed.
     *
     * @param  list<string>  $hidden  column keys the viewer hid
     */
    public static function fromHtml(string $html, string $title, string $slug, array $hidden = []): self
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sr-only ")]') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $table = $xpath->query('//table[@data-list]')->item(0);

        if (! $table instanceof DOMElement) {
            return new self($title, $slug, [], []);
        }

        $columns = [];

        foreach ($xpath->query('./thead/tr/th[@data-col]', $table) ?: [] as $th) {
            /** @var DOMElement $th */
            $key = $th->getAttribute('data-col');
            $label = self::text($th);

            // Hidden by the viewer, or a column without a heading (row actions such as Delete).
            if (! in_array($key, $hidden, true) && $label !== '') {
                $columns[$key] = [
                    'label' => $label,
                    'align' => str_contains(' '.$th->getAttribute('class').' ', ' text-end ') ? 'end' : 'start',
                ];
            }
        }

        $rows = [];

        // Skeleton rows (aria-hidden) are not rows.
        foreach ($xpath->query('./tbody/tr[not(@aria-hidden)]', $table) ?: [] as $tr) {
            $cells = [];

            foreach ($xpath->query('./td[@data-col] | ./th[@data-col]', $tr) ?: [] as $td) {
                /** @var DOMElement $td */
                $key = $td->getAttribute('data-col');

                if (array_key_exists($key, $columns)) {
                    $cells[$key] = self::text($td);
                }
            }

            $rows[] = $cells;
        }

        return new self($title, $slug, $columns, $rows);
    }

    private static function text(DOMElement $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
