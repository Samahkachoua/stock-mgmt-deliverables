<?php

namespace App\Livewire\Items;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\RemembersListState;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Items: every customer's items, searchable by code or name and filterable by
 * customer and status (active / inactive). Filters and page are kept in the
 * session, never in the URL.
 */
#[Layout('components.layouts.app')]
class ItemList extends Component
{
    use DefersRows, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 10;

    public string $search = '';

    public string $customer = '';

    /** '' (all), 'active' or 'inactive'. */
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->clearListState();
    }

    protected function listStateKey(): string
    {
        return 'items';
    }

    protected function listStateProperties(): array
    {
        return ['search', 'customer', 'status'];
    }

    public function render(): View
    {
        $customers = Customer::orderBy('name')->pluck('name', 'id')->all();

        $items = $this->rowsOnceLoaded(fn () => $this->pageInRange(fn () => Product::query()
            ->with(['customer', 'baseUnit', 'units.unit'])
            ->when(array_key_exists($this->customer, $customers), fn ($query) => $query->where('customer_id', $this->customer))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($query) => $query->where('active', $this->status === 'active'))
            ->when(trim($this->search) !== '', fn ($query) => $query->search($this->search))
            ->orderBy('sku')
            ->paginate(self::PER_PAGE)));

        return view('livewire.items.list', [
            'items' => $items,
            'customers' => $customers,
            'statuses' => ['active' => __('settings.active'), 'inactive' => __('settings.inactive')],
        ])->title(__('items.title'));
    }
}
