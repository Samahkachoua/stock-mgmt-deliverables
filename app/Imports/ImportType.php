<?php

namespace App\Imports;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One kind of import (items, opening balances): its template columns, how a
 * row is checked, and how checked rows are written. The engine
 * (ImportEngine) does the rest the same way for every kind.
 */
abstract class ImportType
{
    /** import_job.job_type */
    abstract public function code(): string;

    /** URL segment and lang key. */
    abstract public function slug(): string;

    /**
     * @return list<Column>
     */
    abstract public function columns(): array;

    /**
     * Checks every row of a file together (codes are looked up once, and
     * duplicates within the file are found). Keyed by import_row.id.
     *
     * @param  Collection<int, ImportRow>  $rows
     * @return array<string, RowCheck>
     */
    abstract public function check(Collection $rows, User $user): array;

    /**
     * Writes the rows that passed the check. Runs inside the engine's
     * transaction, right after the check was run again.
     *
     * @param  Collection<int, ImportRow>  $rows
     * @return array<string, mixed> what was created, stored on the job
     */
    abstract public function write(Collection $rows, ImportJob $job, User $user): array;

    /**
     * Code lists for the template's second sheet: title => [code => name].
     *
     * @return array<string, array<string, string>>
     */
    abstract public function codeLists(User $user): array;

    /**
     * Permissions needed to use this import (all of them).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['product.import'];
    }

    public function allows(User $user): bool
    {
        return collect($this->permissions())->every(fn (string $permission) => $user->hasPermission($permission));
    }

    /** resources/icons/<icon>.svg */
    abstract public function icon(): string;

    public function title(): string
    {
        return __('imports.types.'.$this->langKey().'.title');
    }

    /**
     * Warehouses a file touches (opening balances), so only their users see the job.
     *
     * @param  Collection<int, ImportRow>  $rows
     * @return list<string>|null null when the import isn't tied to warehouses
     */
    public function warehouseIds(Collection $rows): ?array
    {
        return null;
    }

    /**
     * The column with this key.
     */
    public function column(string $key): Column
    {
        foreach ($this->columns() as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        throw new \InvalidArgumentException("No column [$key].");
    }

    protected function langKey(): string
    {
        return str_replace('-', '_', $this->slug());
    }

    /**
     * ":column is missing." when $value is empty; true when it is filled.
     */
    protected function required(RowCheck $check, string $key, string $value): bool
    {
        if (trim($value) === '') {
            $check->error('imports.errors.required', ['column' => $key]);

            return false;
        }

        return true;
    }

    /**
     * ":column “X” was not found. Did you mean “Y”?"
     *
     * @param  iterable<string>  $candidates
     */
    protected function unknown(RowCheck $check, string $key, string $value, iterable $candidates): void
    {
        $suggestion = Values::closest($value, $candidates);

        $check->error('imports.errors.unknown', ['column' => $key, 'value' => $value], $suggestion);
    }

    /**
     * ":column is too long (at most :max characters)."
     */
    protected function maxLength(RowCheck $check, string $key, string $value, int $max): void
    {
        if (mb_strlen($value) > $max) {
            $check->error('imports.errors.too_long', ['column' => $key, 'max' => $max]);
        }
    }

    /**
     * Rows that passed the check (with or without warnings).
     *
     * @param  Collection<int, ImportRow>  $rows
     * @return Collection<int, ImportRow>
     */
    protected function importable(Collection $rows): Collection
    {
        return $rows->filter(fn (ImportRow $row) => in_array($row->status, ['VALID', 'WARNING'], true))->values();
    }
}
