<?php

namespace App\Livewire\Settings;

use App\Models\Driver;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Notify;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings › Drivers, shared by all warehouses: the people who take delivery
 * notes out (chosen in the Dispatch dialog). Each action has its own
 * permission (DriverPolicy). A driver on any delivery note can't be deleted.
 *
 * @extends MasterDataPage<Driver>
 */
class Drivers extends MasterDataPage
{
    /** Longest value per field (same as the driver table). */
    public const MAX = ['name' => 100, 'licence_no' => 30, 'national_id' => 30, 'phone' => 30, 'truck_no' => 30];

    /** Fields kept in capitals, so "tr-77" and "TR-77" are the same truck. */
    private const UPPER = ['licence_no', 'national_id', 'truck_no'];

    /**
     * "Delete" on a row: asks first, unless the driver is on delivery notes:
     * then it says why they can't be deleted instead.
     */
    public function askDelete(string $id): void
    {
        $driver = $this->find($id);
        $this->authorize('delete', $driver);
        $this->resetErrorBag('delete.'.$id);

        if ($blocked = $this->deleteBlocked($driver)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        $this->askToConfirm(
            'delete', $id,
            __('confirm.delete.driver.title', ['name' => $driver->name]),
            __('confirm.delete.driver.body', ['name' => $driver->name]),
            __('confirm.delete.driver.button'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        $action === 'delete' ? $this->delete($id) : parent::confirmed($action, $id, $reason);
    }

    /**
     * "Karim is on 12 delivery notes and can’t be deleted.", or null when they can be.
     */
    private function deleteBlocked(Driver $driver): ?string
    {
        $notes = $driver->notesCount();

        return $notes > 0
            ? trans_choice('confirm.blocked.driver_in_use', $notes, ['name' => $driver->name, 'count' => Format::qty($notes)])
            : null;
    }

    private function delete(string $id): void
    {
        $driver = $this->find($id);
        $this->authorize('delete', $driver);

        if ($blocked = $this->deleteBlocked($driver)) {
            $this->addError('delete.'.$id, $blocked);

            return;
        }

        DB::transaction(function () use ($driver) {
            Audit::record('driver', $driver->id, 'DELETE', $this->snapshot($driver), null);
            $driver->delete();
        });

        if ($this->editing === $id) {
            $this->editing = null;
        }

        Notify::success(__('settings.drivers.deleted', ['name' => $driver->name]), on: $this);
    }

    protected function model(): string
    {
        return Driver::class;
    }

    protected function entity(): string
    {
        return 'driver';
    }

    protected function blank(): array
    {
        return ['name' => '', 'licence_no' => '', 'national_id' => '', 'phone' => '', 'truck_no' => ''];
    }

    protected function rules(): array
    {
        $ignore = $this->editing ?: null;

        return [
            'form.name' => ['required', 'string', 'max:'.self::MAX['name']],
            'form.licence_no' => ['required', 'string', 'max:'.self::MAX['licence_no'], Rule::unique('driver', 'licence_no')->ignore($ignore)],
            'form.national_id' => ['required', 'string', 'max:'.self::MAX['national_id'], Rule::unique('driver', 'national_id')->ignore($ignore)],
            'form.phone' => ['required', 'string', 'max:'.self::MAX['phone'], 'regex:/^\+?[0-9][0-9 ()\-]*$/'],
            'form.truck_no' => ['required', 'string', 'max:'.self::MAX['truck_no']],
        ];
    }

    protected function messages(): array
    {
        $messages = [
            'form.name.required' => __('settings.drivers.errors.name_required'),
            'form.licence_no.required' => __('settings.drivers.errors.licence_required'),
            'form.licence_no.unique' => __('settings.drivers.errors.licence_taken'),
            'form.national_id.required' => __('settings.drivers.errors.national_id_required'),
            'form.national_id.unique' => __('settings.drivers.errors.national_id_taken'),
            'form.phone.required' => __('settings.drivers.errors.phone_required'),
            'form.phone.regex' => __('settings.drivers.errors.phone_format'),
            'form.truck_no.required' => __('settings.drivers.errors.truck_required'),
        ];

        foreach (self::MAX as $field => $max) {
            $messages['form.'.$field.'.max'] = __('settings.errors.too_long', ['max' => $max]);
        }

        return $messages;
    }

    /**
     * Trim every field; licence, national ID and truck in capitals.
     */
    protected function normalize(array $form): array
    {
        foreach ($form as $field => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $form[$field] = in_array($field, self::UPPER, true) ? mb_strtoupper($value) : $value;
            }
        }

        return $form;
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $like = '%'.mb_strtolower($search).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(licence_no) like ?', [$like])
            ->orWhereRaw('lower(national_id) like ?', [$like])
            ->orWhereRaw('lower(phone) like ?', [$like])
            ->orWhereRaw('lower(truck_no) like ?', [$like]));
    }

    /**
     * @param  Builder<Driver>  $query
     * @return LengthAwarePaginator<int, Driver>
     */
    protected function paginateRows(Builder $query): LengthAwarePaginator
    {
        return $query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE);
    }

    /**
     * @param  Driver  $record
     */
    protected function displayName(Model $record): string
    {
        return $record->name;
    }

    protected function viewName(): string
    {
        return 'livewire.settings.drivers';
    }

    protected function pageTitle(): string
    {
        return __('settings.tabs.drivers');
    }

    protected function langPrefix(): string
    {
        return 'settings.drivers';
    }
}
