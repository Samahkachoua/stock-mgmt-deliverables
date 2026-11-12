<?php

namespace App\Livewire;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\User;
use App\Reports\Dashboard as Figures;
use App\Reports\Reports;
use App\Support\ListState;
use App\Support\Notify;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The owner dashboard (dashboard.view): figures, orders by branch, workload by warehouse and two short
 * tables (App\Reports\Dashboard). Filters are kept in the session like a
 * list's (CLAUDE.md "List state"); a figure opens its report or list through
 * open(), which hands the same filters over in the session, never the URL.
 * The figures load right after the first paint (skeletons until then).
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    /** @var array<string, string> filter name => value (normalized) */
    public array $filters = [];

    /** "Orders by branch" shows every branch, not only the busiest. */
    public bool $allBranches = false;

    /** "Delivery discrepancies by branch" shows every branch, not only the most. */
    public bool $allDiscrepancies = false;

    /** "Workload by customer" shows every customer, not only the busiest. */
    public bool $allCustomers = false;

    public function mount(): void
    {
        Gate::authorize('dashboard.view');
        $this->filters = $this->figures()->normalize([], $this->user());
    }

    public function updatedFilters(): void
    {
        $this->filters = $this->figures()->normalize($this->filters, $this->user());

        if ($this->figures()->dates($this->filters)->invalid()) {
            Notify::error(__('feedback.date_order'), on: $this);
        }
    }

    /**
     * A customer of "Workload by customer": the whole dashboard narrows to it
     * (one the card shows for this user's warehouses and dates).
     */
    public function showCustomer(string $customer): void
    {
        Gate::authorize('dashboard.view');
        $user = $this->user();
        $filters = $this->figures()->normalize($this->filters, $user);

        $known = collect($this->figures()->byCustomer(['customer' => ''] + $filters, $user))->contains('customer_id', $customer);
        abort_unless($known, 404);

        $this->filters = $this->figures()->normalize(['customer' => $customer] + $filters, $user);
    }

    public function resetFilters(): void
    {
        $this->clearListState();
        $this->filters = $this->figures()->normalize([], $this->user());
    }

    /**
     * Opens what a figure counts: a report with the dashboard's filters, or
     * (a branch of "Orders by branch") the Orders list on that branch.
     */
    public function open(string $target, string $branch = ''): void
    {
        Gate::authorize('dashboard.view');
        $user = $this->user();
        $filters = $this->figures()->normalize($this->filters, $user);

        if ($target === 'orders') {
            $this->openOrders($filters, $user, $branch);

            return;
        }

        $report = Reports::get($target);
        Gate::authorize($report->permission());

        // A branch of "Delivery discrepancies by branch": one the dashboard
        // counts for this user; its customer comes from there, not the browser.
        if ($branch !== '' && $report->key() === 'delivery-differences') {
            $row = collect($this->figures()->discrepanciesByBranch($filters, $user))->firstWhere('branch_id', $branch);
            abort_if($row === null, 404);
            $filters = ['customer' => $row['customer_id'], 'branch' => $row['branch_id']] + $filters;
        }

        ListState::put('reports.'.$report->key(), ['filters' => $this->figures()->reportFilters($report->key(), $filters, $user)]);
        $this->redirect(route('reports.show', $report->key()), navigate: true);
    }

    public function render(): View
    {
        $figures = $this->figures();
        $user = $this->user();
        $dateError = $figures->dates($this->filters)->invalid();
        $loaded = $this->rowsLoaded;
        $canReport = collect(Reports::all())->map(fn ($report) => Gate::allows($report->permission()))->all();

        return view('livewire.dashboard', [
            'definitions' => $figures->filters(),
            'choices' => collect($figures->filters())->mapWithKeys(fn ($filter) => [$filter->name => $filter->choices($user, $this->filters)])->all(),
            'defaults' => $figures->normalize([], $user),
            'dateError' => $dateError,
            'period' => $figures->dates($this->filters),
            'loaded' => $loaded,
            // Orders by branch: only for users who may open the Orders list it links to.
            'branches' => $loaded && $this->canOpenOrders() ? $figures->ordersByBranch($this->filters, $user) : null,
            'ordersKind' => Figures::ordersKind(),
            // Discrepancies by branch: only for users who may open the report it links to.
            'discrepancies' => $loaded && ($canReport['delivery-differences'] ?? false) ? $figures->discrepanciesByBranch($this->filters, $user) : null,
            'warehouses' => $loaded ? $figures->byWarehouse($this->filters, $user) : [],
            'customers' => $loaded ? $figures->byCustomer($this->filters, $user) : null,
            'customerGroups' => $figures->typeGroups(),
            'expiring' => $loaded ? $figures->table('expiry', $this->filters, $user) : null,
            'dormant' => $loaded ? $figures->table('dormant-stock', $this->filters, $user) : null,
            // Figures that open a report only link for users who may open that report (key => bool).
            'canReport' => $canReport,
        ])->title(__('dashboard.title'));
    }

    protected function listStateKey(): string
    {
        return 'dashboard';
    }

    protected function listStateProperties(): array
    {
        return ['filters'];
    }

    protected function restoredListState(): void
    {
        $this->filters = $this->figures()->normalize($this->filters, $this->user());
    }

    /**
     * Opens the Orders list on one branch (and the dashboard's dates). The
     * branch must be one the dashboard counts for this user; its customer
     * comes from there, not from the browser.
     *
     * @param  array<string, string>  $filters
     */
    private function openOrders(array $filters, User $user, string $branch): void
    {
        $kind = Figures::ordersKind();
        abort_unless($kind !== null && $this->canOpenOrders(), 403);

        $row = collect($this->figures()->ordersByBranch($filters, $user))->firstWhere('branch_id', $branch);
        abort_if($row === null, 404);

        $dates = $this->figures()->dates($filters);
        ListState::put($kind->slug(), [
            'search' => '',
            'customer' => $row['customer_id'],
            'branch' => $row['branch_id'],
            'dateFrom' => $dates->from,
            'dateTo' => $dates->to,
            'status' => '',
        ]);
        $this->redirect($kind->route(), navigate: true);
    }

    private function canOpenOrders(): bool
    {
        $kind = Figures::ordersKind();

        return $kind !== null && Gate::allows('drafts.'.strtolower($kind->code).'.open');
    }

    private function figures(): Figures
    {
        return new Figures;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
