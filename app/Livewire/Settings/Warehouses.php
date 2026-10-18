<?php

namespace App\Livewire\Settings;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings › Warehouses. Warehouses are never deleted (stock history points at
 * them); they are taken out of use instead, and only once they hold no stock.
 *
 * @extends MasterDataPage<Warehouse>
 */
class Warehouses extends MasterDataPage
{
    protected function model(): string
    {
        return Warehouse::class;
    }

    protected function entity(): string
    {
        return 'warehouse';
    }

    protected function blank(): array
    {
        return ['code' => '', 'name' => '', 'city' => '', 'address' => '', 'active' => true];
    }

    protected function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:20', Rule::unique('warehouse', 'code')->ignore($this->editing ?: null)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.city' => ['required', 'string', 'max:100'],
            'form.address' => ['nullable', 'string', 'max:500'],
            'form.active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.code.required' => __('settings.warehouses.errors.code_required'),
            'form.code.max' => __('settings.errors.too_long', ['max' => 20]),
            'form.code.unique' => __('settings.warehouses.errors.code_taken'),
            'form.name.required' => __('settings.warehouses.errors.name_required'),
            'form.name.max' => __('settings.errors.too_long', ['max' => 150]),
            'form.city.required' => __('settings.warehouses.errors.city_required'),
            'form.city.max' => __('settings.errors.too_long', ['max' => 100]),
            'form.address.max' => __('settings.errors.too_long', ['max' => 500]),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(code) like ?', [$like])
            ->orWhereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(city) like ?', [$like]));
    }

    /**
     * @param  Warehouse  $record
     */
    protected function check(Model $record, array $data): array
    {
        if ($record->exists && $record->active && ! $data['active'] && self::holdsStock($record->id)) {
            return ['active' => __('settings.warehouses.errors.has_stock')];
        }

        return [];
    }

    /**
     * Stock on hand here, or a transfer on its way here.
     */
    public static function holdsStock(string $warehouseId): bool
    {
        return DB::table('stock_balance')->where('warehouse_id', $warehouseId)->where('qty_base', '>', 0)->exists()
            || DB::table('stock_transaction')->where('to_warehouse_id', $warehouseId)->where('status', 'DISPATCHED')->exists();
    }

    protected function viewName(): string
    {
        return 'livewire.settings.warehouses';
    }

    protected function pageTitle(): string
    {
        return __('settings.tabs.warehouses');
    }

    protected function langPrefix(): string
    {
        return 'settings.warehouses';
    }
}
