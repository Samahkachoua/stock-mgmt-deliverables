<?php

namespace App\Livewire\Transactions;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\Customer;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Ids;
use App\Support\Notify;
use App\Support\Transactions\Documents;
use App\Support\Transactions\Statuses;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
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

    /** One of the user's warehouses, or 'all' of them; starts on their default warehouse. */
    public string $warehouse = '';

    /** @var array<string, string>|null per-request cache: the user's warehouses, id => name */
    private ?array $warehouses = null;

    public function mount(string $type): void
    {
        $this->type = TransactionTypes::get($type)->code;
        Gate::authorize($this->typeUi()->viewGate());
        $this->warehouse = $this->validWarehouse($this->warehouse);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'dateFrom', 'dateTo', 'status', 'direction', 'warehouse'], true)) {
            $this->resetPage();
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'warehouse') {
            $this->warehouse = $this->validWarehouse($this->warehouse);
        }

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
        // Back to the user's default warehouse.
        $this->warehouse = $this->validWarehouse('');
    }

    protected function listStateKey(): string
    {
        return $this->typeUi()->slug();
    }

    protected function listStateProperties(): array
    {
        return ['search', 'customer', 'dateFrom', 'dateTo', 'status', 'direction', 'warehouse'];
    }

    protected function restoredListState(): void
    {
        $this->dateFrom = DateRange::day($this->dateFrom);
        $this->dateTo = DateRange::day($this->dateTo);
        $this->warehouse = $this->validWarehouse($this->warehouse);

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
            // A warehouse filter (and column) only for users who work in more than one.
            'warehouses' => count($this->warehouseOptions()) > 1
                ? ['all' => __('transactions.filters.all_warehouses')] + $this->warehouseOptions()
                : null,
        ])->title($type->label('title'));
    }

    /**
     * Documents of this type in the chosen warehouse, or all the user's.
     *
     * @return LengthAwarePaginator<int, ListRow>
     */
    private function documents(TypeUi $type, bool $pendingFirst): LengthAwarePaginator
    {
        $warehouseIds = $this->warehouse === 'all' ? array_keys($this->warehouseOptions()) : [$this->warehouse];
        $dates = $this->dates();

        // "Date from" after "Date to": no query; the page shows the error.
        if ($dates->invalid()) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE, 1);
        }

        return $this->pageInRange(fn (int $page) => Documents::page($type, array_map('strval', $warehouseIds), [
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

    /**
     * The user's active warehouses, id => name.
     *
     * @return array<string, string>
     */
    private function warehouseOptions(): array
    {
        $user = Auth::user();

        return $this->warehouses ??= $user instanceof User ? $user->assignedWarehouses()->pluck('name', 'id')->all() : [];
    }

    /**
     * 'all', or one of the user's warehouses; anything else (nothing yet, a
     * warehouse taken away) becomes their default warehouse.
     */
    private function validWarehouse(string $value): string
    {
        if ($value === 'all' || array_key_exists($value, $this->warehouseOptions())) {
            return $value;
        }

        $user = Auth::user();

        return $user instanceof User ? (string) $user->defaultWarehouseId() : '';
    }

    private function typeUi(): TypeUi
    {
        return TransactionTypes::get($this->type);
    }
}
