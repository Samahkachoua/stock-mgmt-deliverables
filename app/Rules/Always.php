<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A closure rule that also runs when the value is empty (Laravel skips normal
 * rules for empty values). For rules like "a filled row needs a unit" where
 * emptiness itself is the error.
 */
final class Always implements ValidationRule
{
    public bool $implicit = true;

    /**
     * @param  Closure(string, mixed, Closure): void  $check
     */
    public function __construct(private readonly Closure $check) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        ($this->check)($attribute, $value, $fail);
    }
}
