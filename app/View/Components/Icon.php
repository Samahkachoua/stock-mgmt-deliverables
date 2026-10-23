<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * Inline Material Symbols (outlined) icon from resources/icons/<name>.svg.
 * Usage: <x-icon name="outbox" class="size-5 text-type-delivery" />
 */
class Icon extends Component
{
    /** @var array<string, string> */
    protected static array $cache = [];

    public function __construct(public string $name) {}

    public function svg(): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $this->name)) {
            throw new InvalidArgumentException("Invalid icon name [{$this->name}].");
        }

        return static::$cache[$this->name] ??= file_get_contents(resource_path("icons/{$this->name}.svg"))
            ?: throw new InvalidArgumentException("Icon [{$this->name}] not found in resources/icons.");
    }

    public function render(): View
    {
        return view('components.icon');
    }
}
