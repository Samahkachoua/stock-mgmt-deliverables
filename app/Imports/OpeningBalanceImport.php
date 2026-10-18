<?php

namespace App\Imports;

use App\Models\Customer;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Availability;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\PostingService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances from Excel (SRS FR-10.4), checked like the Opening Balance
 * form: a warehouse the user works in, the customer's item, a unit the item
 * has, a quantity, and the expiry date for expiry items. On confirm, one
 * Opening Balance document per warehouse and customer, posted through
 * PostingService.
 *
 * Serial-numbered items take one row per piece: its serial number, and a
 * quantity of 1 (or none).
 */
final class OpeningBalanceImport extends ImportType
{
    public function code(): string
    {
        return 'OPENING_BALANCE';
    }

    public function icon(): string
    {
        return 'account_balance_wallet';
    }

    public function slug(): string
    {
        return 'opening-balance';
    }

    public function permissions(): array
    {
        return ['product.import', 'stock.opening.create'];
    }

    public function columns(): array
    {
        return [
            new Column('warehouse_code', required: true),
            new Column('customer_code', required: true),
            new Column('item_code', required: true),
            new Column('expiry_date', kind: 'date'),
            new Column('quantity', required: true),
            new Column('unit'),
            new Column('serial_no'),
        ];
    }

    public function codeLists(User $user): array
    {
        return [
            __('imports.codes.warehouses') => $user->assignedWarehouses()->sortBy('code')->pluck('name', 'code')->all(),
            __('imports.codes.customers') => Customer::where('active', true)->orderBy('code')->pluck('name', 'code')->all(),
            __('imports.codes.items') => Product::with('customer')->where('active', true)->orderBy('sku')->get()
                ->mapWithKeys(fn (Product $p) => [$p->sku.' ('.$p->customer->code.')' => $p->name()])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    public function warehouseIds(Collection $rows): array
    {
        $codes = $rows->map(fn ($row) => mb_strtoupper(trim($row->payload['values']['warehouse_code'] ?? '')))->filter()->unique();

        return Warehouse::query()->get(['id', 'code'])
            ->filter(fn (Warehouse $w) => $codes->contains(mb_strtoupper($w->code)))
            ->pluck('id')->values()->all();
    }

    public function check(Collection $rows, User $user): array
    {
        $warehouses = Warehouse::query()->get(['id', 'code', 'name', 'active', 'timezone'])->keyBy(fn (Warehouse $w) => mb_strtoupper($w->code));
        $customers = Customer::query()->get(['id', 'code', 'active'])->keyBy(fn (Customer $c) => mb_strtoupper($c->code));
        $products = Product::query()->with(['baseUnit', 'units.unit'])
            ->whereIn('customer_id', $customers->pluck('id'))
            ->get()
            ->keyBy(fn (Product $p) => $p->customer_id.'|'.mb_strtoupper($p->sku));
        $stocked = StockBalance::query()->where('qty_base', '>', 0)->get(['warehouse_id', 'customer_id', 'product_id'])
            ->mapWithKeys(fn (StockBalance $b) => [$b->warehouse_id.'|'.$b->customer_id.'|'.$b->product_id => true]);

        $serialsInStock = DB::table('serial_number')->whereIn('product_id', $products->where('track_serial', true)->pluck('id'))
            ->whereIn('status', ['IN_STOCK', 'IN_TRANSIT'])->get(['product_id', 'serial_no'])
            ->mapWithKeys(fn ($s) => [$s->product_id.'|'.$s->serial_no => true]);

        $serialRows = []; // customer|item|serial => rows of the file it is on
        foreach ($rows as $row) {
            $v = array_map(fn ($value) => trim((string) $value), $row->payload['values']);

            if (($v['serial_no'] ?? '') !== '') {
                $serialRows[mb_strtoupper(($v['customer_code'] ?? '').'|'.($v['item_code'] ?? '')).'|'.$v['serial_no']][] = $row->row_no;
            }
        }

        $checks = [];

        foreach ($rows as $row) {
            $v = array_map(fn ($value) => trim((string) $value), $row->payload['values']);
            $check = new RowCheck;

            // Warehouse: one the user works in.
            $warehouse = null;
            if ($this->required($check, 'warehouse_code', $v['warehouse_code'] ?? '')) {
                $warehouse = $warehouses[mb_strtoupper($v['warehouse_code'])] ?? null;

                if ($warehouse === null || ! $warehouse->active) {
                    $this->unknown($check, 'warehouse_code', $v['warehouse_code'], $warehouses->where('active', true)->pluck('code'));
                    $warehouse = null;
                } elseif (! $user->canAccessWarehouse($warehouse->id)) {
                    $check->error('imports.errors.warehouse_not_yours', ['value' => $warehouse->code]);
                    $warehouse = null;
                }
            }

            // Customer
            $customer = null;
            if ($this->required($check, 'customer_code', $v['customer_code'] ?? '')) {
                $customer = $customers[mb_strtoupper($v['customer_code'])] ?? null;

                if ($customer === null) {
                    $this->unknown($check, 'customer_code', $v['customer_code'], $customers->pluck('code'));
                } elseif (! $customer->active) {
                    $check->error('imports.errors.customer_inactive', ['value' => $customer->code]);
                    $customer = null;
                }
            }

            // Item of that customer
            $product = null;
            if ($this->required($check, 'item_code', $v['item_code'] ?? '') && $customer !== null) {
                $product = $products[$customer->id.'|'.mb_strtoupper($v['item_code'])] ?? null;

                if ($product === null) {
                    $suggestion = Values::closest($v['item_code'], $products->where('customer_id', $customer->id)->pluck('sku'));
                    $check->error('imports.errors.item_unknown', ['value' => $v['item_code'], 'customer' => $customer->code], $suggestion);
                } elseif (! $product->active) {
                    $check->error('imports.errors.item_inactive', ['value' => $product->sku]);
                    $product = null;
                }
            }

            // Unit (empty = base unit) and quantity
            $unit = $product?->converter()->base();
            if ($product !== null && ($v['unit'] ?? '') !== '') {
                $unit = $product->units->first(fn ($pu) => mb_strtoupper($pu->unit->code) === mb_strtoupper($v['unit']))?->unit->code;

                if ($unit === null) {
                    $check->error('imports.errors.unit_not_for_item', [
                        'value' => $v['unit'],
                        'item' => $product->sku,
                        'units' => $product->units->pluck('unit.code')->implode(', '),
                    ]);
                }
            }

            // Serial items: one row per piece, so a serial number and a quantity of 1 (or none).
            if ($product !== null && $product->track_serial) {
                $serial = $v['serial_no'] ?? '';
                $qty = ($v['quantity'] ?? '') === '' ? '1' : Values::number($v['quantity']);

                if ($qty === null || (float) $qty != 1) {
                    $check->error('imports.errors.serial_quantity', ['item' => $product->sku, 'value' => $v['quantity']]);
                }

                if ($serial === '') {
                    $check->error('imports.errors.serial_required', ['item' => $product->sku]);
                } else {
                    $this->maxLength($check, 'serial_no', $serial, 150);

                    $others = array_values(array_diff($serialRows[mb_strtoupper($v['customer_code'].'|'.$v['item_code']).'|'.$serial] ?? [], [$row->row_no]));
                    if ($others !== []) {
                        $check->error('imports.errors.serial_twice_in_file', ['value' => $serial, 'rows' => implode(', ', $others)]);
                    }

                    if (isset($serialsInStock[$product->id.'|'.$serial])) {
                        $check->error('stock.errors.serial_in_stock', ['serial' => $serial]);
                    }
                }
            } elseif ($this->required($check, 'quantity', $v['quantity'] ?? '')) {
                $qty = Values::number($v['quantity']);

                if ($qty === null || ! PostingService::whole($qty) || (float) $qty <= 0) {
                    $check->error('imports.errors.quantity', ['value' => $v['quantity']]);
                } elseif ($product !== null && $unit !== null) {
                    $base = $product->converter()->toBase($qty, $unit);

                    if (round($base, 4) != $base) {
                        $check->error('stock.errors.not_exact');
                    }
                }
            }

            // Expiry date: required for expiry items, ignored for others.
            if ($product !== null && $product->track_expiry) {
                $expiry = Values::date($v['expiry_date'] ?? '');

                if (($v['expiry_date'] ?? '') === '') {
                    $check->error('imports.errors.expiry_required', ['item' => $product->sku]);
                } elseif ($expiry === null) {
                    $check->error('imports.errors.date', ['value' => $v['expiry_date']]);
                }

                if ($expiry !== null && $warehouse !== null && $expiry < CarbonImmutable::now($warehouse->timezone)->toDateString()) {
                    $check->warn('imports.warnings.expired', ['date' => Format::date(CarbonImmutable::parse($expiry))]);
                }
            } elseif ($product !== null && ($v['expiry_date'] ?? '') !== '') {
                $check->warn('imports.warnings.expiry_ignored', ['item' => $product->sku]);
            }

            if ($product !== null && ! $product->track_serial && ($v['serial_no'] ?? '') !== '') {
                $check->warn('imports.warnings.serial_ignored', ['item' => $product->sku]);
            }

            if ($warehouse !== null && $customer !== null && $product !== null && isset($stocked[$warehouse->id.'|'.$customer->id.'|'.$product->id])) {
                $check->warn('imports.warnings.adds_to_stock', ['item' => $product->sku, 'warehouse' => $warehouse->code]);
            }

            $checks[$row->id] = $check;
        }

        return $checks;
    }

    public function write(Collection $rows, ImportJob $job, User $user): array
    {
        $warehouses = Warehouse::query()->get(['id', 'code'])->keyBy(fn (Warehouse $w) => mb_strtoupper($w->code));
        $customers = Customer::query()->get(['id', 'code'])->keyBy(fn (Customer $c) => mb_strtoupper($c->code));
        $products = Product::query()->with(['baseUnit', 'units.unit'])
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereIn('sku', $rows->map(fn (ImportRow $row) => mb_strtoupper(trim($row->payload['values']['item_code'] ?? '')))->unique())
            ->get()
            ->keyBy(fn (Product $p) => $p->customer_id.'|'.mb_strtoupper($p->sku));
        $documents = [];

        // One Opening Balance per warehouse and customer, lines in file order.
        $groups = $this->importable($rows)->groupBy(fn (ImportRow $row) => mb_strtoupper(trim($row->payload['values']['warehouse_code'])).'|'.mb_strtoupper(trim($row->payload['values']['customer_code'])));

        foreach ($groups as $key => $group) {
            [$warehouseCode, $customerCode] = explode('|', (string) $key);
            $warehouse = $warehouses[$warehouseCode];
            $customer = $customers[$customerCode];
            $lines = [];

            foreach ($group as $row) {
                $v = array_map(fn ($value) => trim((string) $value), $row->payload['values']);
                $product = $products[$customer->id.'|'.mb_strtoupper($v['item_code'])];
                $unit = ($v['unit'] ?? '') === ''
                    ? $product->converter()->base()
                    : $product->units->first(fn ($pu) => mb_strtoupper($pu->unit->code) === mb_strtoupper($v['unit']))->unit->code;

                // Keyed by spreadsheet row, so a refusal names the row.
                $lines['row'.$row->row_no] = new DocumentLine(
                    productId: $product->id,
                    unit: $unit,
                    qty: $product->track_serial ? '1' : (string) Values::number($v['quantity']),
                    expiry: $product->track_expiry ? Values::date($v['expiry_date']) : null,
                    serials: $product->track_serial ? [$v['serial_no']] : [],
                );
            }

            $transaction = app(PostingService::class)->post(new Document(
                type: 'OPENING_BALANCE',
                warehouseId: $warehouse->id,
                customerId: $customer->id,
                date: app(Availability::class)->today($warehouse->id),
                note: __('imports.document_note', ['file' => $job->file_name]),
                lines: $lines,
            ), $user);

            $documents[] = $transaction->doc_no;
        }

        return ['documents' => $documents, 'lines' => $groups->flatten()->count()];
    }
}
