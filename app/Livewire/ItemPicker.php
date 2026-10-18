<?php

namespace App\Livewire;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\Customer;
use App\Services\Stock\ItemCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Searchable item dropdown, limited to one customer's active items. Reusable anywhere:
 * <livewire:item-picker wire:model.live="lines.r1.item" :customer="$customerId" input-id="..." />
 * The value is the product id. Search (code, or name in any language) is
 * debounced and shows at most MAX_RESULTS matches, with what is in stock in the
 * current warehouse.
 */
class ItemPicker extends Component
{
    public const MAX_RESULTS = ItemCatalog::MAX_RESULTS;

    /** The chosen product id, shared with the parent's wire:model. */
    #[Modelable]
    public ?string $value = null;

    /** customer.id whose items are offered. */
    #[Reactive]
    public string $customer = '';

    #[Reactive]
    public bool $invalid = false;

    /** id for the search input / chosen-item button, so labels and focus can target it. */
    public string $inputId = '';

    /** id of the field to focus after an item is chosen (the row's unit). */
    public string $next = '';

    public string $search = '';

    public bool $open = false;

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function results(): array
    {
        return $this->customer === '' ? [] : ItemCatalog::search($this->customer, $this->search, $this->warehouseId(), self::MAX_RESULTS);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function chosen(): ?array
    {
        return $this->customer === '' ? null : ItemCatalog::item($this->customer, $this->value, $this->warehouseId());
    }

    public function updatedSearch(): void
    {
        $this->open = true;
    }

    public function choose(string $productId): void
    {
        if (ItemCatalog::item($this->customer, $productId, $this->warehouseId()) === null) {
            return;
        }

        $this->value = $productId;
        $this->search = '';
        $this->open = false;

        if ($this->next !== '') {
            $this->js('document.getElementById('.json_encode($this->next).')?.focus()');
        }
    }

    /**
     * Back to searching (keeps the current item until another is chosen).
     */
    public function change(): void
    {
        $this->search = '';
        $this->open = true;
        $this->js('document.getElementById('.json_encode($this->inputId).')?.focus()');
    }

    public function close(): void
    {
        $this->open = false;
        $this->search = '';
    }

    public function render(): View
    {
        return view('livewire.item-picker', [
            'customerName' => $this->customer === '' ? '' : (string) Customer::whereKey($this->customer)->value('name'),
        ]);
    }

    private function warehouseId(): ?string
    {
        return session(EnsureCurrentWarehouse::SESSION_KEY);
    }
}
