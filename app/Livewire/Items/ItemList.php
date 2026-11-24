<?php

namespace App\Livewire\Items;

use App\Livewire\Concerns\DefersRows;
use App\Livewire\Concerns\ExportsList;
use App\Livewire\Concerns\RemembersListState;
use App\Models\Customer;
use App\Models\Product;
use App\Reports\Filter;
use App\Support\StorageStatuses;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * Items: every customer's items, searchable by code or name and filterable by
 * customer, status (active / inactive) and storage status. Filters and page
 * are kept in the session, never in the URL.
 */
#[Layout('components.layouts.app')]
class ItemList extends Component
{
    use DefersRows, ExportsList, RemembersListState, WithoutUrlPagination, WithPagination;

    public const PER_PAGE = 10;

    public string $search = '';

    public string $customer = '';

    /** '' (all), 'active' or 'inactive'. */
    public string $status = '';

    /** '' (all), a storage_status code, or Filter::NO_STORAGE (not set). */
    public string $storage = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'customer', 'status', 'storage'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->clearListState();
    }

    protected function exportTitle(): string
    {
        return __('items.title');
    }

    protected function authorizeExport(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    protected function listStateKey(): string
    {
        return 'items';
    }

    protected function listStateProperties(): array
    {
        return ['search', 'customer', 'status', 'storage'];
    }

    public function render(): View
    {
        $customers = Customer::orderBy('name')->pluck('name', 'id')->all();
        $storages = StorageStatuses::options() + [Filter::NO_STORAGE => __('items.storage.none')];

        $items = $this->rowsOnceLoaded(fn () => $this->pageInRange(fn () => Product::query()
            ->with(['customer', 'baseUnit', 'units.unit'])
            ->when(array_key_exists($this->customer, $customers), fn ($query) => $query->where('customer_id', $this->customer))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($query) => $query->where('active', $this->status === 'active'))
            ->when($this->storage === Filter::NO_STORAGE, fn ($query) => $query->whereNull('storage_status'))
            ->when($this->storage !== Filter::NO_STORAGE && array_key_exists($this->storage, $storages), fn ($query) => $query->where('storage_status', $this->storage))
            ->when(trim($this->search) !== '', fn ($query) => $query->search($this->search))
            ->orderBy('sku')
            ->paginate($this->perPage())));

        return view('livewire.items.list', [
            'items' => $items,
            'customers' => $customers,
            'statuses' => ['active' => __('settings.active'), 'inactive' => __('settings.inactive')],
            'storages' => $storages,
        ])->title(__('items.title'));
    }
}
