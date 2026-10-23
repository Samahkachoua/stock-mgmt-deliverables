<?php

namespace App\Livewire\Drafts;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Drafts\DraftDocuments;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Ids;
use App\Support\Notify;
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
 * The list of draft receipts / orders the user may see (DraftScope), newest
 * first; for those who may confirm them, drafts still to confirm come first.
 * Filters and page are kept in the session, never in the URL.
 *
 * @phpstan-import-type DraftRow from DraftDocuments
 */
#[Layout('components.layouts.app')]
class DraftList extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 8;

    /** transaction_type.code the drafts create, from the route. */
    #[Locked]
    public string $type = '';

    public string $search = '';

    public string $customer = '';

    public string $branch = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $status = '';

    public function mount(string $type): void
    {
        $this->type = DraftTypes::get($type)->code;
        Gate::authorize('drafts.'.strtolower($this->type).'.open');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'branch', 'dateFrom', 'dateTo', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function updated(string $property): void
    {
        // Branches belong to a customer: another customer, another list of branches.
        if ($property === 'customer') {
            $this->branch = '';
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
    }

    protected function listStateKey(): string
    {
        return $this->kind()->slug();
    }

    protected function listStateProperties(): array
    {
        return ['search', 'customer', 'branch', 'dateFrom', 'dateTo', 'status'];
    }

    protected function restoredListState(): void
    {
        $this->dateFrom = DateRange::day($this->dateFrom);
        $this->dateTo = DateRange::day($this->dateTo);

        if (! array_key_exists($this->status, DraftTypes::statusOptions())) {
            $this->status = '';
        }
    }

    public function render(): View
    {
        $kind = $this->kind();
        $draftsFirst = Gate::allows($kind->confirmPermission());

        return view('livewire.drafts.list', [
            'kind' => $kind,
            'rows' => $this->rowsOnceLoaded(fn () => $this->drafts($kind, $draftsFirst && $this->status === '')),
            'customers' => Customer::where('active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => $this->branchOptions(),
            'statuses' => DraftTypes::statusOptions(),
            'draftsFirst' => $draftsFirst,
            'dateError' => $this->dates()->invalid(),
        ])->title($kind->label('title'));
    }

    /**
     * Branches to filter on: of the chosen customer (a customer user: theirs),
     * limited to the user's own branches when they have some.
     *
     * @return array<string, string>
     */
    private function branchOptions(): array
    {
        $user = Auth::user();
        $customer = $user instanceof User && $user->isCustomerUser() ? $user->customer_id : $this->customer;

        return CustomerBranch::query()
            ->when(Ids::valid($customer), fn ($query) => $query->where('customer_id', $customer))
            ->when($user instanceof User && $user->branchIds() !== [], fn ($query) => $query->whereIn('id', $user->branchIds()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, DraftRow>
     */
    private function drafts(DraftUi $kind, bool $draftsFirst): LengthAwarePaginator
    {
        $dates = $this->dates();

        if ($dates->invalid()) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE, 1);
        }

        return $this->pageInRange(fn (int $page) => DraftDocuments::page($kind, [
            'search' => $this->search,
            'customer' => Ids::valid($this->customer) ? $this->customer : '',
            'branch' => Ids::valid($this->branch) ? $this->branch : '',
            'status' => array_key_exists($this->status, DraftTypes::statusOptions()) ? $this->status : '',
            'dates' => $dates,
            'drafts_first' => $draftsFirst,
        ], self::PER_PAGE, $page));
    }

    private function dates(): DateRange
    {
        return new DateRange($this->dateFrom, $this->dateTo);
    }

    private function kind(): DraftUi
    {
        return DraftTypes::get($this->type);
    }
}
