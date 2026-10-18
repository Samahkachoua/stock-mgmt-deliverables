<?php

namespace App\Imports;

/**
 * One column of an import template. Its header is translated
 * (lang/<locale>/imports.php `columns.<key>`); required columns end in " *".
 * An uploaded file is matched by header text in any of the app's languages,
 * so a file filled in from the Arabic template can be uploaded in French.
 */
final class Column
{
    public function __construct(
        public readonly string $key,
        public readonly bool $required = false,
        /** text | date: dates are read from Excel's date cells too */
        public readonly string $kind = 'text',
    ) {}

    public function header(?string $locale = null): string
    {
        return __('imports.columns.'.$this->key, [], $locale).($this->required ? ' *' : '');
    }

    /**
     * The header as written in the user's language, for messages.
     */
    public function label(): string
    {
        return __('imports.columns.'.$this->key);
    }

    /**
     * Header texts this column is recognised by: every language, with or
     * without the "*", case and spaces ignored.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_values(array_unique(array_map(
            fn (string $locale) => self::normalize(__('imports.columns.'.$this->key, [], $locale)),
            ['en', 'ar', 'fr'],
        )));
    }

    public static function normalize(string $header): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $header)) ?? ''));
    }
}
