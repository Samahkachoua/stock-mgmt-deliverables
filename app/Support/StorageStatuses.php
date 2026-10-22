<?php

namespace App\Support;

use App\Models\StorageStatus;

/**
 * Item storage statuses from the storage_status table, translated:
 * "Chilled (2 to 8 °C)". The one place the item form, the import, the item
 * picker and the printouts get their wording from.
 */
final class StorageStatuses
{
    /** @var array<string, StorageStatus>|null */
    private static ?array $rows = null;

    /**
     * @return array<string, StorageStatus> keyed by code, in display_order
     */
    public static function all(): array
    {
        return self::$rows ??= StorageStatus::query()->orderBy('display_order')->get()->keyBy('code')->all();
    }

    /**
     * code => "Chilled (2 to 8 °C)", for dropdowns and (plain, for Excel) the import's code list.
     *
     * @return array<string, string>
     */
    public static function options(bool $plain = false): array
    {
        return array_map(fn (StorageStatus $status) => (string) self::label($status->code, $plain), self::all());
    }

    /**
     * "Chilled (2 to 8 °C)", or null for an item without a storage status.
     */
    public static function label(?string $code, bool $plain = false): ?string
    {
        $status = $code === null ? null : (self::all()[$code] ?? null);

        if ($status === null) {
            return null;
        }

        return __('items.storage.label', [
            'name' => __('items.storage.codes.'.$status->code),
            'range' => __('items.storage.range', ['min' => self::degrees($status->temp_min_c, $plain), 'max' => self::degrees($status->temp_max_c, $plain)]),
        ]);
    }

    public static function flush(): void
    {
        self::$rows = null;
    }

    /**
     * A temperature isolated left-to-right (U+2066 … U+2069), so "-18" keeps
     * its minus sign in front in Arabic text too, on screen and on paper.
     */
    private static function degrees(int $value, bool $plain): string
    {
        return $plain ? Format::qty($value) : "\u{2066}".Format::qty($value)."\u{2069}";
    }
}
