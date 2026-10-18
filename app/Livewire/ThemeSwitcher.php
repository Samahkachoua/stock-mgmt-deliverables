<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Theme;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Light / Dark / System. Alpine switches the <html> class instantly; this
 * component only saves the choice (no re-render, no reload).
 */
class ThemeSwitcher extends Component
{
    public string $theme = 'system';

    public function mount(): void
    {
        $this->theme = Theme::current();
    }

    public function setTheme(string $theme): void
    {
        if (! Theme::isValid($theme)) {
            throw ValidationException::withMessages(['theme' => __('app.theme_invalid')]);
        }

        $user = auth()->user();

        if ($user instanceof User) {
            $user->update(['preferred_theme' => $theme]);
        } else {
            session()->put('theme', $theme);
        }

        $this->theme = $theme;
        $this->skipRender();
    }

    public function render(): View
    {
        return view('livewire.theme-switcher');
    }
}
