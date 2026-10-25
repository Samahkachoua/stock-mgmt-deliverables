<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Services\Items\ItemWriter;
use App\Support\StorageStatuses;
use Illuminate\Support\Collection;

/**
 * Items from Excel (SRS FR-10.1): the same rules as the item form, checked for
 * every row, and written through ItemWriter. Codes are unique per customer,
 * in the file and in the database.
 */
final class ProductImport extends ImportType
{
    /** Alternate unit columns: Alternate unit 1 / Factor 1 … */
    public const ALTERNATE_UNITS = 3;

    private const CODE_FORMAT = '/^[A-Z0-9][A-Z0-9._\/-]*$/';

    public function code(): string
    {
        return 'PRODUCT';
    }

    public function icon(): string
    {
        return 'category';
    }

    public function slug(): string
    {
        return 'items';
    }

    public function columns(): array
    {
        $columns = [
            new Column('customer_code', required: true),
            new Column('item_code', required: true),
            new Column('name_en', required: true),
            new Column('name_ar'),
            new Column('name_fr'),
            new Column('base_unit', required: true),
            new Column('tracks_expiry'),
            new Column('tracks_serial'),
            new Column('is_service'),
            new Column('storage_status'),
            new Column('approval_limit'),
        ];

        for ($i = 1; $i <= self::ALTERNATE_UNITS; $i++) {
            $columns[] = new Column('alternate_unit_'.$i);
            $columns[] = new Column('factor_'.$i);
        }

        return $columns;
    }

    public function codeLists(User $user): array
    {
        return [
            __('imports.codes.customers') => Customer::where('active', true)->orderBy('code')->pluck('name', 'code')->all(),
            __('imports.codes.units') => Unit::orderBy('code')->get()->mapWithKeys(fn (Unit $unit) => [$unit->code => $unit->label()])->all(),
            __('imports.codes.storage_statuses') => StorageStatuses::options(plain: true),
            __('imports.codes.yes_no') => ['Y' => __('imports.codes.yes'), 'N' => __('imports.codes.no')],
        ];
    }

    public function check(Collection $rows, User $user): array
    {
        $customers = Customer::query()->get(['id', 'code', 'name', 'active'])->keyBy(fn (Customer $c) => mb_strtoupper($c->code));
        $units = Unit::all()->keyBy(fn (Unit $u) => mb_strtoupper($u->code));
        $storageStatuses = StorageStatuses::all();

        // Item codes already used, per customer of the file.
        $customerIds = $rows->map(fn ($row) => $customers[mb_strtoupper(trim($row->payload['values']['customer_code'] ?? ''))]->id ?? null)->filter()->unique();
        $taken = Product::withoutGlobalScopes()->whereIn('customer_id', $customerIds)->get(['customer_id', 'sku'])
            ->mapWithKeys(fn (Product $p) => [$p->customer_id.'|'.mb_strtoupper($p->sku) => true]);

        // Where each customer + code appears in the file.
        $inFile = $rows->groupBy(fn ($row) => mb_strtoupper(trim($row->payload['values']['customer_code'] ?? '')).'|'.mb_strtoupper(trim($row->payload['values']['item_code'] ?? '')))
            ->map(fn (Collection $group) => $group->pluck('row_no')->all());

        $checks = [];

        foreach ($rows as $row) {
            $v = array_map(fn ($value) => trim((string) $value), $row->payload['values']);
            $check = new RowCheck;

            // Customer
            $customer = null;
            if ($this->required($check, 'customer_code', $v['customer_code'] ?? '')) {
                $customer = $customers[mb_strtoupper($v['customer_code'])] ?? null;

                if ($customer === null) {
                    $this->unknown($check, 'customer_code', $v['customer_code'], $customers->pluck('code'));
                } elseif (! $customer->active) {
                    $check->error('imports.errors.customer_inactive', ['value' => $customer->code]);
                }
            }

            // Item code: format, once per customer in the file, not yet in the database.
            $sku = mb_strtoupper($v['item_code'] ?? '');
            if ($this->required($check, 'item_code', $sku)) {
                $this->maxLength($check, 'item_code', $sku, 50);

                if (! preg_match(self::CODE_FORMAT, $sku)) {
                    $check->error('imports.errors.code_format', ['value' => $v['item_code']]);
                }

                $others = array_values(array_diff($inFile[mb_strtoupper($v['customer_code'] ?? '').'|'.$sku] ?? [], [$row->row_no]));
                if ($others !== []) {
                    $check->error('imports.errors.code_twice_in_file', ['value' => $sku, 'rows' => implode(', ', $others)]);
                }

                if ($customer !== null && isset($taken[$customer->id.'|'.$sku])) {
                    $check->error('imports.errors.code_exists', ['value' => $sku, 'customer' => $customer->code]);
                }
            }

            // Names
            if ($this->required($check, 'name_en', $v['name_en'] ?? '')) {
                $this->maxLength($check, 'name_en', $v['name_en'], 200);
            }
            foreach (['name_ar', 'name_fr'] as $key) {
                $this->maxLength($check, $key, $v[$key] ?? '', 200);
            }
            if (($v['name_ar'] ?? '') === '' || ($v['name_fr'] ?? '') === '') {
                $check->warn('imports.warnings.missing_names');
            }

            // Base unit
            $base = null;
            if ($this->required($check, 'base_unit', $v['base_unit'] ?? '')) {
                $base = $units[mb_strtoupper($v['base_unit'])] ?? null;

                if ($base === null) {
                    $this->unknown($check, 'base_unit', $v['base_unit'], $units->pluck('code'));
                }
            }

            // Y / N flags
            $flags = [];
            foreach (['tracks_expiry', 'tracks_serial', 'is_service'] as $key) {
                $flags[$key] = Values::flag($v[$key] ?? '');

                if ($flags[$key] === null) {
                    $check->error('imports.errors.flag', ['column' => $key, 'value' => $v[$key]]);
                }
            }
            if ($flags['is_service'] && ($flags['tracks_expiry'] || $flags['tracks_serial'])) {
                $check->error('items.errors.service_untracked');
            } elseif ($flags['tracks_expiry'] && $flags['tracks_serial']) {
                $check->error('items.errors.serial_and_expiry');
            }

            // Storage status: optional, one of the codes.
            if (($v['storage_status'] ?? '') !== '' && ! isset($storageStatuses[self::storageCode($v['storage_status'])])) {
                $this->unknown($check, 'storage_status', $v['storage_status'], collect(array_keys($storageStatuses)));
            }

            // Approval limit
            if (($v['approval_limit'] ?? '') !== '' && (($limit = Values::number($v['approval_limit'])) === null || (float) $limit < 0)) {
                $check->error('imports.errors.limit', ['value' => $v['approval_limit']]);
            }

            // Alternate units
            $this->checkUnits($check, $v, $units, $base, (bool) $flags['tracks_serial'], (bool) $flags['is_service']);

            $checks[$row->id] = $check;
        }

        return $checks;
    }

    public function write(Collection $rows, ImportJob $job, User $user): array
    {
        $customers = Customer::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id]);
        $units = Unit::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id]);
        $writer = app(ItemWriter::class);
        $created = 0;

        foreach ($this->importable($rows) as $row) {
            $v = array_map(fn ($value) => trim((string) $value), $row->payload['values']);
            $serial = (bool) Values::flag($v['tracks_serial'] ?? '');
            $service = (bool) Values::flag($v['is_service'] ?? '');
            $alternates = [];

            for ($i = 1; $i <= self::ALTERNATE_UNITS; $i++) {
                if (($v['alternate_unit_'.$i] ?? '') !== '') {
                    $alternates[] = ['unit_id' => $units[mb_strtoupper($v['alternate_unit_'.$i])], 'factor' => (string) Values::number($v['factor_'.$i], 6)];
                }
            }

            $writer->save(new Product, [
                'customer_id' => $customers[mb_strtoupper($v['customer_code'])],
                'sku' => mb_strtoupper($v['item_code']),
                'name_en' => $v['name_en'],
                'name_ar' => ($v['name_ar'] ?? '') ?: null,
                'name_fr' => ($v['name_fr'] ?? '') ?: null,
                'base_unit_id' => $units[mb_strtoupper($v['base_unit'])],
                'track_expiry' => (bool) Values::flag($v['tracks_expiry'] ?? ''),
                'track_serial' => $serial,
                'is_service' => $service,
                'storage_status' => ($v['storage_status'] ?? '') === '' ? null : self::storageCode($v['storage_status']),
                'approval_threshold_qty' => ($v['approval_limit'] ?? '') === '' ? null : Values::number($v['approval_limit']),
                'active' => true,
            ], $alternates, $user->id);

            $created++;
        }

        return ['items' => $created];
    }

    /**
     * "deep frozen" / "Deep-Frozen" → DEEP_FROZEN.
     */
    private static function storageCode(string $value): string
    {
        return str_replace([' ', '-'], '_', mb_strtoupper($value));
    }

    /**
     * Alternate units: a known unit, not the base unit, not twice, with a
     * factor of more than 1 (1 Box = 12 Piece); none on serial items.
     *
     * @param  array<string, string>  $v
     * @param  Collection<string, Unit>  $units
     */
    private function checkUnits(RowCheck $check, array $v, Collection $units, ?Unit $base, bool $serial, bool $service = false): void
    {
        $seen = [];

        for ($i = 1; $i <= self::ALTERNATE_UNITS; $i++) {
            $code = $v['alternate_unit_'.$i] ?? '';
            $factor = $v['factor_'.$i] ?? '';

            if ($code === '' && $factor === '') {
                continue;
            }

            if ($service) {
                $check->error('imports.errors.service_units');

                return;
            }

            if ($serial) {
                $check->error('imports.errors.serial_units');

                return;
            }

            if ($code === '') {
                $check->error('imports.errors.unit_missing', ['column' => 'alternate_unit_'.$i]);

                continue;
            }

            $unit = $units[mb_strtoupper($code)] ?? null;

            if ($unit === null) {
                $this->unknown($check, 'alternate_unit_'.$i, $code, $units->pluck('code'));

                continue;
            }

            if ($base !== null && $unit->id === $base->id) {
                $check->error('imports.errors.unit_is_base', ['unit' => $unit->code]);
            } elseif (isset($seen[$unit->id])) {
                $check->error('imports.errors.unit_twice', ['unit' => $unit->code]);
            }
            $seen[$unit->id] = true;

            $number = Values::number($factor, 6);

            if ($factor === '') {
                $check->error('imports.errors.factor_missing', ['unit' => $unit->code]);
            } elseif ($number === null || (float) $number >= 1e12) {
                $check->error('imports.errors.factor_number', ['unit' => $unit->code, 'value' => $factor]);
            } elseif ((float) $number <= 1) {
                $base === null
                    ? $check->error('imports.errors.factor_more_than_one_plain', ['unit' => $unit->code])
                    : $check->error('imports.errors.factor_more_than_one', ['unit' => $unit->code, 'base' => $base->code]);
            }
        }
    }
}
