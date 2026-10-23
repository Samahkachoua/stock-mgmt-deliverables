<?php

namespace App\Livewire\Concerns;

use App\Support\ListState;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A list that keeps its filters and page in the session (App\Support\ListState),
 * never in the URL. Use it with WithPagination and WithoutUrlPagination, and
 * no #[Url] attributes.
 *
 * The state is restored right after the component's mount() and saved after
 * every request; clearListState() is what "Clear filters" calls.
 */
trait RemembersListState
{
    /**
     * Session key of this list, e.g. "delivery-note" or "reports.stock-card".
     */
    abstract protected function listStateKey(): string;

    /**
     * Public properties that make up the state (filters, search, sorting).
     *
     * @return list<string>
     */
    protected function listStateProperties(): array
    {
        return [];
    }

    /**
     * Runs after restoring, e.g. to check restored values again.
     */
    protected function restoredListState(): void {}

    public function mountRemembersListState(): void
    {
        $state = ListState::get($this->listStateKey());

        if ($state === null) {
            return;
        }

        foreach ($this->listStateProperties() as $property) {
            if (array_key_exists($property, $state['properties']) && gettype($state['properties'][$property]) === gettype($this->{$property})) {
                $this->{$property} = $state['properties'][$property];
            }
        }

        $this->restoredListState();
        $this->setPage($state['page']);
    }

    public function dehydrateRemembersListState(): void
    {
        $properties = [];

        foreach ($this->listStateProperties() as $property) {
            $properties[$property] = $this->{$property};
        }

        ListState::put($this->listStateKey(), $properties, (int) $this->getPage());
    }

    /**
     * "Clear filters": every state property back to its default, first page.
     */
    protected function clearListState(): void
    {
        if ($this->listStateProperties() !== []) {
            $this->reset(...$this->listStateProperties());
        }

        $this->resetPage();
        ListState::forget($this->listStateKey());
    }

    /**
     * The rows of the current page; a remembered page that no longer exists
     * (fewer rows now, another warehouse) becomes the last page.
     *
     * @template TPaginator of LengthAwarePaginator
     *
     * @param  Closure(int): TPaginator  $fetch  rows of the given page
     * @return TPaginator
     */
    protected function pageInRange(Closure $fetch): LengthAwarePaginator
    {
        $rows = $fetch((int) $this->getPage());

        if ($rows->isEmpty() && $this->getPage() > 1) {
            $this->setPage(max(1, $rows->lastPage()));
            $rows = $fetch((int) $this->getPage());
        }

        return $rows;
    }
}
