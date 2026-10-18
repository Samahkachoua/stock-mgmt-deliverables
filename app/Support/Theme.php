<?php

namespace App\Support;

use App\Models\User;

class Theme
{
    /**
     * The theme to render: the user's saved choice, a guest's session choice, or `system`.
     */
    public static function current(): string
    {
        $user = auth()->user();

        $theme = $user instanceof User ? $user->preferred_theme : session('theme');

        return self::isValid($theme) ? $theme : 'system';
    }

    public static function isValid(mixed $theme): bool
    {
        return is_string($theme) && in_array($theme, config('ui.themes'), true);
    }
}
