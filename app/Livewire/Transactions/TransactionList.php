<?php

namespace App\Livewire\Transactions;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\Customer;
use App\Support\DateRange;
use App\Support\Ids;
use App\Support\Notify;
use App\Support\Transactions\Documents;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The list page shared by every transaction type (CLAUDE.md "Screen patterns" 1).
 * Lists the documents of the current warehouse (App\Support\Transactions\Documents).
 * Users who may approve the type see documents waiting for approval first,
 * unless they filter on a status. Filters and page are kept in the session,
 * per type (CLAUDE.md "List state"), never in the URL.
 *
 * @phpstan-import-type ListRow from Documents
 */
#[Layout('components.layouts.app')]
class TransactionList extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 8;

    /** transaction_type.code, from the route. */
    #[Locked]
    public string $type = '';

    public string $search = '';

    public string $customer = '';

    /** "Date from" / "Date to" on the document's own date; empty = no date filter. */
    public string $dateFrom = '';

    public string $dateTo = '';

    public string $status = '';

    /** Transfers: '' (both), incoming or outgoing. */
    public string $direction = '';

    public function mount(string $type): void
    {
        Gate::authorize('stock.view');

        $this->type = TransactionTypes::get($type)->code;
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'dateFrom', 'dateTo', 'status', 'direction'], true)) {
            $this->resetPage();
        }
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['dateFrom', 'dateTo'], true)) {
            return;
        }

        $this->{$property} = DateRange::day($this->{$property});

        if ($this->dates()->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);
        }
    }

    public function resetFilters(): void
    {
        $this->clearListState();
    }

    protected function listStateKey(): string
    {
        return $this->typeUi()->slug();
    }

    protected function listStateProperties(): array
    {
        return ['search', 'customer', 'dateFrom', 'dateTo', 'status', 'direction'];
    }

    protected function restoredListState(): void
    {
        $this->dateFrom = DateRange::day($this->dateFrom);
        $this->dateTo = DateRange::day($this->dateTo);

        // A remembered status this type can't have would filter on a choice the list no longer offers.
        if (! array_key_exists($this->status, Statuses::options($this->typeUi()))) {
            $this->status = '';
        }
    }

    public function render(): View
    {
        $type = $this->typeUi();
        $pendingFirst = $type->approvePermission() !== null && Gate::allows($type->approvePermission());

        return view('livewire.transactions.list', [
            'ui' => $type,
            'rows' => $this->rowsOnceLoaded(fn () => $this->documents($type, $pendingFirst && $this->status === '')),
            'customers' => Customer::orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => Statuses::options($type),
            'pendingFirst' => $pendingFirst,
            'dateError' => $this->dates()->invalid(),
            'directions' => $type->dispatchReceive() ? [
                '' => __('transfers.direction.all'),
                'incoming' => __('transfers.direction.incoming'),
                'outgoing' => __('transfers.direction.outgoing'),
            ] : null,
        ])->title($type->label('title'));
    }

    /**
     * Documents of this type in the current warehouse.
     *
     * @return LengthAwarePaginator<int, ListRow>
     */
    private function documents(TypeUi $type, bool $pendingFirst): LengthAwarePaginator
    {
        $warehouseId = (string) session(EnsureCurrentWarehouse::SESSION_KEY);
        $dates = $this->dates();

        // "Date from" after "Date to": no query; the page shows the error.
        if ($dates->invalid()) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE, 1);
        }

        return $this->pageInRange(fn (int $page) => Documents::page($type, $warehouseId, [
            'search' => $this->search,
            'customer' => Ids::valid($this->customer) ? $this->customer : '',
            'status' => $this->status,
            'dates' => $dates,
            'direction' => in_array($this->direction, ['incoming', 'outgoing'], true) ? $this->direction : '',
            'pending_first' => $pendingFirst,
        ], self::PER_PAGE, $page));
    }

    private function dates(): DateRange
    {
        return new DateRange($this->dateFrom, $this->dateTo);
    }

    private function typeUi(): TypeUi
    {
        return TransactionTypes::get($this->type);
    }
}
