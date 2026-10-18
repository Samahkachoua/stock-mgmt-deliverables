<?php

namespace App\Imports;

/**
 * What checking one row found: errors (the row can't be imported) and
 * warnings (it can, but the user should know).
 *
 * Messages are kept as translation keys with their values, not as text, so
 * every viewer reads them in their own language (RowMessages::render()). A
 * `column` value is a column key, shown as that column's header.
 */
final class RowCheck
{
    /** @var list<array{k: string, p: array<string, string|int>}> */
    public array $errors = [];

    /** @var list<array{k: string, p: array<string, string|int>}> */
    public array $warnings = [];

    /**
     * @param  array<string, string|int>  $params
     * @param  string|null  $suggestion  adds "Did you mean …?"
     */
    public function error(string $key, array $params = [], ?string $suggestion = null): void
    {
        $this->errors[] = ['k' => $key, 'p' => $params];

        if ($suggestion !== null) {
            $this->errors[] = ['k' => 'imports.errors.did_you_mean', 'p' => ['value' => $suggestion]];
        }
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public function warn(string $key, array $params = []): void
    {
        $this->warnings[] = ['k' => $key, 'p' => $params];
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * import_row.status: ERROR, WARNING or VALID.
     */
    public function status(): string
    {
        return match (true) {
            $this->errors !== [] => 'ERROR',
            $this->warnings !== [] => 'WARNING',
            default => 'VALID',
        };
    }

    /**
     * import_row.message: the messages as JSON (errors first), or null.
     */
    public function message(): ?string
    {
        $messages = [...$this->errors, ...$this->warnings];

        return $messages === [] ? null : json_encode($messages, JSON_UNESCAPED_UNICODE);
    }
}
