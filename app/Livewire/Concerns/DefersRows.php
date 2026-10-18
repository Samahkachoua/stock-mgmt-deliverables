<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * A list whose first paint shows skeleton rows instead of waiting for its
 * rows (CLAUDE.md "Loading states"): the page comes back at once with its
 * header and filters, and the table (x-data-table with `loading`) asks for the
 * rows through loadRows().
 *
 * Wrap the rows query in rowsOnceLoaded() and pass `:loading="! $rowsLoaded"`
 * to x-data-table. config('ui.defer_rows') = false loads the rows with the page.
 */
trait DefersRows
{
    /** False until the browser has asked for the rows. */
    public bool $rowsLoaded = false;

    public function mountDefersRows(): void
    {
        $this->rowsLoaded = ! config('ui.defer_rows');
    }

    public function loadRows(): void
    {
        $this->rowsLoaded = true;
    }

    /**
     * The rows, or an empty page while they are not asked for yet.
     *
     * @template TPaginator of LengthAwarePaginator
     *
     * @param  Closure(): TPaginator  $fetch
     * @return TPaginator|Paginator<int, never>
     */
    protected function rowsOnceLoaded(Closure $fetch): LengthAwarePaginator
    {
        return $this->rowsLoaded ? $fetch() : new Paginator([], 0, 1);
    }
}
