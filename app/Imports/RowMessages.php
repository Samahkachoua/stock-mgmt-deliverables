<?php

namespace App\Imports;

/**
 * Turns a stored import_row.message (RowCheck::message()) into sentences in
 * the current language: on the check page, in the error report, in tests.
 */
final class RowMessages
{
    public static function render(?string $stored, ?string $locale = null): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        $messages = json_decode($stored, true);

        // Text stored before messages were kept as keys.
        if (! is_array($messages)) {
            return $stored;
        }

        return implode(' ', array_map(function (array $message) use ($locale) {
            $params = $message['p'] ?? [];

            if (isset($params['column'])) {
                $params['column'] = __('imports.columns.'.$params['column'], [], $locale);
            }

            return __($message['k'], $params, $locale);
        }, $messages));
    }
}
