<?php

namespace App\Livewire\Settings;

use App\Models\Unit;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Notify;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings › Units of measure. A unit can be deleted only while nothing uses it.
 *
 * @extends MasterDataPage<Unit>
 */
class Units extends MasterDataPage
{
    /**
     * "Delete" on a row: asks first, unless the unit is in use: then it says
     * why it cannot be deleted instead.
     */
    public function askDelete(string $id): void
    {
        $unit = Unit::findOrFail($id);
        $this->authorize('delete', $unit);
        $this->resetErrorBag('delete.'.$id);

        if ($blocked = $this->deleteBlocked($unit)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        $this->askToConfirm(
            'delete', $id,
            __('confirm.delete.unit.title', ['name' => $unit->label()]),
            __('confirm.delete.unit.body', ['name' => $unit->label()]),
            __('confirm.delete.unit.button'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        $action === 'delete' ? $this->delete($id) : parent::confirmed($action, $id, $reason);
    }

    /**
     * "Box is used by 12 items and can’t be deleted.", or null when it can be.
     */
    private function deleteBlocked(Unit $unit): ?string
    {
        if (! $unit->inUse()) {
            return null;
        }

        $items = DB::table('product')->where('base_unit_id', $unit->id)->pluck('id')
            ->merge(DB::table('product_unit')->where('unit_id', $unit->id)->pluck('product_id'))
            ->unique()->count();

        return $items > 0
            ? trans_choice('confirm.blocked.unit_in_use', $items, ['name' => $unit->label(), 'count' => Format::qty($items)])
            : __('confirm.blocked.unit_on_documents', ['name' => $unit->label()]);
    }

    private function delete(string $id): void
    {
        $unit = Unit::findOrFail($id);
        $this->authorize('delete', $unit);

        if ($blocked = $this->deleteBlocked($unit)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        DB::transaction(function () use ($unit) {
            Audit::record('unit', $unit->id, 'DELETE', $this->snapshot($unit), null);
            $unit->delete();
        });

        if ($this->editing === $id) {
            $this->editing = null;
        }

        Notify::success(__('settings.units.deleted', ['name' => $unit->label()]), on: $this);
    }

    protected function model(): string
    {
        return Unit::class;
    }

    protected function entity(): string
    {
        return 'unit';
    }

    protected function blank(): array
    {
        return ['code' => '', 'name' => ''];
    }

    protected function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('unit', 'code')->ignore($this->editing ?: null)],
            'form.name' => ['required', 'string', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.code.required' => __('settings.units.errors.code_required'),
            'form.code.max' => __('settings.errors.too_long', ['max' => 20]),
            'form.code.regex' => __('settings.units.errors.code_format'),
            'form.code.unique' => __('settings.units.errors.code_taken'),
            'form.name.required' => __('settings.units.errors.name_required'),
            'form.name.max' => __('settings.errors.too_long', ['max' => 100]),
        ];
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(code) like ?', [$like])
            ->orWhereRaw('lower(name) like ?', [$like]));
    }

    /**
     * @param  Builder<Unit>  $query
     * @return LengthAwarePaginator<int, Unit>
     */
    protected function paginateRows(Builder $query): LengthAwarePaginator
    {
        return $query
            ->addSelect([
                'items_count' => DB::table('product_unit')->selectRaw('count(distinct product_id)')->whereColumn('product_unit.unit_id', 'unit.id'),
            ])
            ->orderBy('code')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @param  Unit  $record
     */
    protected function displayName(Model $record): string
    {
        return $record->label();
    }

    protected function viewName(): string
    {
        return 'livewire.settings.units';
    }

    protected function pageTitle(): string
    {
        return __('settings.tabs.units');
    }

    protected function langPrefix(): string
    {
        return 'settings.units';
    }
}
