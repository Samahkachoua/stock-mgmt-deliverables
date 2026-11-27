<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Models\StockDraft;
use App\Models\User;
use App\Reports\Reports;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Home\CustomerHome;
use App\Support\Home\RecentActivity;
use App\Support\Home\StaffHome;
use App\Support\ListState;
use App\Support\Navigation\Sidebar;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The page after signing in, in two versions on one frame:
 *
 * - staff (owner, manager, operator…): what needs them now, quick starts,
 *   the warehouse at a glance, today's work queue and their recent work
 *   (App\Support\Home\StaffHome);
 * - a customer user: check their stock, what needs their attention, a stock
 *   snapshot, the lots expiring first and their open orders
 *   (App\Support\Home\CustomerHome).
 *
 * A card opens its list already filtered through open(): the filters go into
 * the list's session state, never the URL (CLAUDE.md "List state"). The
 * warehouse filter is kept the same way, under list_state.home.
 */
#[Layout('components.layouts.app')]
class Home extends Component
{
    /** Saved searches of "Check your stock" (per user, in the session). */
    public const RECENT_SEARCHES = 3;

    /** One of the user's warehouses, or 'all' of them; starts on their default warehouse. */
    public string $warehouse = '';

    /** "Check your stock": what to look for, and on which page (total, or by expiry date). */
    public string $stockSearch = '';

    public string $stockView = 'total';

    public function mount(): void
    {
        $state = ListState::get('home');
        $this->warehouse = $this->validWarehouse((string) ($state['properties']['warehouse'] ?? ''));
    }

    public function updatedWarehouse(): void
    {
        $this->warehouse = $this->validWarehouse($this->warehouse);
        ListState::put('home', ['warehouse' => $this->warehouse]);
    }

    /**
     * Opens what a card counts: its list (or report) filtered on it.
     */
    public function open(string $key): void
    {
        $user = $this->user();
        $card = collect($this->cards($user))->firstWhere('key', $key);
        abort_if($card === null, 404);

        if (str_starts_with($key, 'approve.')) {
            $this->openDocuments(TransactionTypes::get(substr($key, strlen('approve.'))), ['status' => 'PENDING_APPROVAL']);

            return;
        }

        match ($key) {
            'dispatched' => $this->openDocuments(TransactionTypes::get('OUT'), ['status' => 'DISPATCHED']),
            'to_dispatch' => $this->openDocuments(TransactionTypes::get('OUT'), ['status' => 'CONFIRMED']),
            'arriving' => $this->openDocuments(TransactionTypes::get('TRANSFER'), ['direction' => 'incoming', 'status' => 'DISPATCHED']),
            'orders', 'editable' => $this->openDrafts(DraftTypes::get('OUT'), 'DRAFT'),
            'receipts' => $this->openDrafts(DraftTypes::get('IN'), 'DRAFT'),
            'on_way' => $this->openOnTheWay($user),
            'expiring' => $this->openExpiring($user),
            default => abort(404),
        };
    }

    /**
     * "Check your stock": the Stock page (or Stock by expiry) showing what
     * matches, in Home's warehouses. $term: one of the recent searches.
     */
    public function searchStock(?string $term = null): void
    {
        $user = $this->user();
        Gate::authorize('stock.view');

        $term = trim(mb_substr($term ?? $this->stockSearch, 0, 100));
        $byExpiry = $this->stockView === 'expiry' && Gate::allows('report.stock_by_expiry.view');

        if ($term !== '') {
            $recent = array_values(array_unique([$term, ...$this->recentSearches()]));
            session()->put('home.stock_searches', ['user' => $user->id, 'terms' => array_slice($recent, 0, self::RECENT_SEARCHES)]);
        }

        $report = Reports::get($byExpiry ? 'stock-by-expiry' : 'stock-on-hand');
        ListState::put($byExpiry ? 'stock-by-expiry' : 'stock', [
            'filters' => $report->normalize(['search' => $term, 'warehouse' => $this->warehouse], $user),
        ]);

        $this->redirect(route($byExpiry ? 'stock-by-expiry' : 'stock'), navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $customer = $user->isCustomerUser();
        $warehouses = $user->assignedWarehouses()->pluck('name', 'id')->all();
        $home = $customer ? new CustomerHome($user, $this->warehouseIds()) : new StaffHome($user, $this->warehouseIds());
        $create = $this->creatable($user);
        $hour = CompanyTime::now()->hour;

        $data = [
            'customerUser' => $customer,
            'greeting' => __('home.greeting.'.match (true) {
                $hour < 12 => 'morning',
                $hour < 18 => 'afternoon',
                default => 'evening',
            }),
            'today' => CompanyTime::today(),
            'warehouses' => count($warehouses) > 1 ? ['all' => __('home.all_warehouses')] + $warehouses : null,
            'warehouseName' => $warehouses[$this->warehouse] ?? __('home.all_warehouses'),
            'cards' => $home->cards(),
            'create' => $create,
            'recent' => RecentActivity::forUser($user),
        ];

        if ($home instanceof CustomerHome) {
            return view('livewire.home.customer', $data + [
                'customerName' => (string) Customer::query()->whereKey($user->customer_id)->value('name'),
                'canSearch' => $user->hasPermission('stock.view'),
                'canByExpiry' => Gate::allows('report.stock_by_expiry.view'),
                'recentSearches' => $this->recentSearches(),
                'snapshot' => $home->snapshot(),
                'soonest' => $home->soonest(),
                'openOrders' => $home->openOrders(),
                'orders' => $home->orders(),
            ])->title(__('home.page_title'));
        }

        return view('livewire.home.staff', $data + [
            'glance' => $home->glance(),
            'queue' => $home->queue(),
            'ordersKind' => Gate::allows('drafts.out.open') ? DraftTypes::all()['OUT'] ?? null : null,
        ])->title(__('home.page_title'));
    }

    /**
     * What the user may start, most used first (config('ui.home_primary')),
     * then in sidebar order. A Delivery note has no "New" page: it comes from
     * an Order. "Add new item" ends the list for whoever manages items.
     *
     * @return Collection<int, array{label: string, title: string, url: string, icon: string, kind: TypeUi|DraftUi|null}>
     */
    private function creatable(User $user): Collection
    {
        $sidebar = array_column(Sidebar::flatItems(), 'route');
        $primary = config('ui.home_primary', []);
        $rank = fn (string $slug) => [
            ($index = array_search($slug, $primary, true)) === false ? count($primary) : $index,
            ($position = array_search($slug, $sidebar, true)) === false ? PHP_INT_MAX : $position,
        ];

        $actions = collect(DraftTypes::all())->values()
            ->concat(array_values(TransactionTypes::all()))
            ->filter(fn (TypeUi|DraftUi $kind) => ($kind instanceof DraftUi || $kind->creatable()) && $user->hasPermission($kind->createPermission()))
            ->sortBy(fn (TypeUi|DraftUi $kind) => $rank($kind->slug()))
            ->map(fn (TypeUi|DraftUi $kind) => [
                'label' => $kind->label('singular'),
                'title' => $kind->label('new'),
                'url' => $kind->route('create'),
                'icon' => $kind->icon(),
                'kind' => $kind,
            ])
            ->values()
            ->all();

        if ($user->hasPermission('product.manage')) {
            $label = __('home.new_item');
            $label = is_string($label) ? $label : 'home.new_item';
            $actions[] = ['label' => $label, 'title' => $label, 'url' => route('items.create'), 'icon' => 'inventory_2', 'kind' => null];
        }

        return collect($actions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cards(User $user): array
    {
        return $user->isCustomerUser()
            ? (new CustomerHome($user, $this->warehouseIds()))->cards()
            : (new StaffHome($user, $this->warehouseIds()))->cards();
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function openDocuments(TypeUi $type, array $filters): void
    {
        Gate::authorize($type->viewGate());

        ListState::put($type->slug(), $filters + [
            'search' => '', 'customer' => '', 'branch' => '', 'dateFrom' => '', 'dateTo' => '',
            'status' => '', 'direction' => '', 'warehouse' => $this->warehouse,
        ]);

        $this->redirect($type->route(), navigate: true);
    }

    private function openDrafts(DraftUi $kind, string $status): void
    {
        Gate::authorize('drafts.'.strtolower($kind->code).'.open');

        ListState::put($kind->slug(), ['search' => '', 'customer' => '', 'branch' => '', 'dateFrom' => '', 'dateTo' => '', 'status' => $status]);

        $this->redirect($kind->route(), navigate: true);
    }

    /**
     * Orders on their way: the order itself when there is one, else the
     * confirmed orders.
     */
    private function openOnTheWay(User $user): void
    {
        $home = new CustomerHome($user, $this->warehouseIds());
        $orders = $home->orders();
        abort_if($orders === null, 403);

        $query = $home->onTheWay($orders);
        if ($query->count() === 1) {
            /** @var StockDraft $order */
            $order = $query->first();
            $this->redirect($orders->route('show', $order->doc_no), navigate: true);

            return;
        }

        $this->openDrafts($orders, 'POSTED');
    }

    /**
     * Lots expiring soon: the Expiry report (exactly what the card counts),
     * else Stock by expiry, else the Stock page, with "expiring" on.
     */
    private function openExpiring(User $user): void
    {
        $days = $user->isCustomerUser() ? CustomerHome::EXPIRY_DAYS : StaffHome::EXPIRY_DAYS;

        if (Gate::allows('report.expiry.view')) {
            $report = Reports::get('expiry');
            ListState::put('reports.expiry', [
                'filters' => $report->normalize(['status' => 'expiring', 'days' => (string) $days, 'warehouse' => $this->warehouse], $user),
            ]);
            $this->redirect(route('reports.show', 'expiry'), navigate: true);

            return;
        }

        $byExpiry = Gate::allows('report.stock_by_expiry.view');
        $report = Reports::get($byExpiry ? 'stock-by-expiry' : 'stock-on-hand');
        ListState::put($byExpiry ? 'stock-by-expiry' : 'stock', [
            'filters' => $report->normalize(['expiring' => '1', 'warehouse' => $this->warehouse], $user),
        ]);
        $this->redirect(route($byExpiry ? 'stock-by-expiry' : 'stock'), navigate: true);
    }

    /**
     * @return list<string>
     */
    private function recentSearches(): array
    {
        $saved = session('home.stock_searches');

        return is_array($saved) && (string) ($saved['user'] ?? '') === (string) $this->user()->id && is_array($saved['terms'] ?? null)
            ? array_values(array_filter($saved['terms'], 'is_string'))
            : [];
    }

    /**
     * @return list<string>
     */
    private function warehouseIds(): array
    {
        return $this->warehouse === 'all' ? $this->user()->warehouseIds() : [$this->warehouse];
    }

    /**
     * One of the user's warehouses or 'all' (only for users with several);
     * anything else becomes their default warehouse.
     */
    private function validWarehouse(string $value): string
    {
        $user = $this->user();

        if ($value === 'all' && count($user->warehouseIds()) > 1) {
            return 'all';
        }

        return $user->canAccessWarehouse($value) ? $value : (string) $user->defaultWarehouseId();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
