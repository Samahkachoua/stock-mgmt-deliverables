<?php

namespace App\Livewire\Concerns;

use App\Support\ListState;

/**
 * "Rows per page" under a list (x-data-table.pagination shows the dropdown
 * for any component using this trait).
 *
 * The choices are config('ui.per_page_options') plus the list's own default
 * (its PER_PAGE constant). The choice is remembered per user and per list in
 * the session (ListState::perPage), never in the URL (CLAUDE.md "List state"),
 * and "Clear filters" keeps it. Changing it goes back to page 1.
 *
 * Use with WithPagination and RemembersListState (its listStateKey() names the
 * list), and paginate with rowsPerPage().
 */
trait WithPerPage
{
    /** Rows per page; 0 until mounted. Only ever one of perPageOptions(). */
    public int $perPage = 0;

    public function mountWithPerPage(): void
    {
        $this->perPage = $this->validPerPage(ListState::perPage($this->listStateKey()) ?? 0);
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->validPerPage($this->perPage);
        ListState::putPerPage($this->listStateKey(), $this->perPage);
        $this->resetPage();
    }

    /**
     * The sizes the dropdown offers, smallest first.
     *
     * @return list<int>
     */
    public function perPageOptions(): array
    {
        $options = array_map('intval', (array) config('ui.per_page_options'));
        $options[] = static::PER_PAGE;
        $options = array_values(array_unique(array_filter($options, fn (int $size) => $size > 0)));
        sort($options);

        return $options;
    }

    /**
     * The checked size to paginate with.
     */
    protected function rowsPerPage(): int
    {
        return $this->validPerPage($this->perPage);
    }

    /**
     * One of the offered sizes; anything else is the list's default.
     */
    private function validPerPage(int $size): int
    {
        return in_array($size, $this->perPageOptions(), true) ? $size : static::PER_PAGE;
    }
}
