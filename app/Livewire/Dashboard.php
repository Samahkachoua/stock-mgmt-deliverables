<?php

namespace App\Livewire;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\User;
use App\Reports\Dashboard as Figures;
use App\Reports\Reports;
use App\Support\ListState;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The owner dashboard (dashboard.view): figures, two charts and two short
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

    public function resetFilters(): void
    {
        $this->clearListState();
        $this->filters = $this->figures()->normalize([], $this->user());
    }

    /**
     * Opens what a figure counts: a report with the dashboard's filters, or
     * (pending approvals) the list of the type that has waited longest.
     */
    public function open(string $target): void
    {
        Gate::authorize('dashboard.view');
        $user = $this->user();
        $filters = $this->figures()->normalize($this->filters, $user);

        if ($target === 'pending') {
            $code = $this->figures()->tiles($filters, $user)['pending']['type'];
            $type = $code !== null ? TransactionTypes::get($code) : null;
            abort_unless($type !== null && Gate::allows($type->viewGate()), 403);

            ListState::put($type->slug(), ['status' => 'PENDING_APPROVAL', 'warehouse' => $filters['warehouse'], 'customer' => $filters['customer']]);
            $this->redirect($type->route(), navigate: true);

            return;
        }

        $status = $target === 'expired' ? 'expired' : 'all';
        $report = Reports::get($target === 'expired' ? 'expiry' : $target);
        Gate::authorize($report->permission());

        ListState::put('reports.'.$report->key(), ['filters' => $this->figures()->reportFilters($report->key(), $filters, $user, $status)]);
        $this->redirect(route('reports.show', $report->key()), navigate: true);
    }

    public function render(): View
    {
        $figures = $this->figures();
        $user = $this->user();
        $dateError = $figures->dates($this->filters)->invalid();
        $loaded = $this->rowsLoaded;
        $tiles = $loaded ? $figures->tiles($this->filters, $user) : null;
        $pendingType = $tiles['pending']['type'] ?? null;

        return view('livewire.dashboard', [
            'definitions' => $figures->filters(),
            'choices' => collect($figures->filters())->mapWithKeys(fn ($filter) => [$filter->name => $filter->choices($user, $this->filters)])->all(),
            'defaults' => $figures->normalize([], $user),
            'dateError' => $dateError,
            'period' => $figures->dates($this->filters),
            'tiles' => $tiles,
            // Pending approvals open the list of the type that has waited longest.
            'pendingOpens' => $pendingType !== null && Gate::allows(TransactionTypes::get($pendingType)->viewGate()),
            'activity' => $loaded ? $figures->activity($this->filters, $user) : null,
            'warehouses' => $loaded ? $figures->byWarehouse($this->filters, $user) : [],
            'expiring' => $loaded ? $figures->table('expiry', $this->filters, $user) : null,
            'dormant' => $loaded ? $figures->table('dormant-stock', $this->filters, $user) : null,
            // Figures that open a report only link for users who may open it.
            'canReports' => Gate::allows('report.view'),
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
