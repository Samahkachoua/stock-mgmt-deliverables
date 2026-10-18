<?php

namespace App\Livewire;

use App\Http\Middleware\EnsureCurrentWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Ids;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Top-bar warehouse selector. Lists only the user's warehouses (user_warehouse);
 * the choice is kept in the session and the page reloads in the new warehouse.
 *
 * @property-read Collection<int, Warehouse> $warehouses
 */
class WarehouseSwitcher extends Component
{
    public string $selected = '';

    public function mount(): void
    {
        $this->selected = (string) session(EnsureCurrentWarehouse::SESSION_KEY, '');
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return $this->user()->assignedWarehouses();
    }

    public function updatedSelected(string $id): void
    {
        $this->select($id);
    }

    public function select(string $id): void
    {
        abort_unless(Ids::valid($id) && $this->warehouses->contains('id', $id), 403);

        session()->put(EnsureCurrentWarehouse::SESSION_KEY, $id);

        $this->redirect(url()->previous(route('home')));
    }

    public function render(): View
    {
        return view('livewire.warehouse-switcher');
    }

    protected function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
