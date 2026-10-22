<?php

namespace App\Livewire\Items;

use App\Livewire\Concerns\ConfirmsActions;
use App\Livewire\Concerns\WarnsBeforeLeaving;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Unit;
use App\Rules\Always;
use App\Services\Items\ItemWriter;
use App\Services\Stock\UnitConverter;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Notify;
use App\Support\StorageStatuses;
use App\Support\Transactions\TransactionTypes;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New / edit item, in three parts: the item (customer, code, names), its units
 * ("1 Box = 12 Piece") and tracking (expiry, serial numbers, approval limit).
 *
 * The unit rules are the ones trg_validate_product_unit enforces, checked here
 * first so they are explained: the base unit is the smallest unit and has its
 * own row at factor 1; every other unit is more than 1 base unit; no unit twice;
 * serial-numbered items use only their base unit. Once an item has stock
 * movements its customer, base unit and tracking can no longer change.
 *
 * Cancel, the back link or any other link asks first when something was
 * changed (WarnsBeforeLeaving).
 *
 * @property-read Collection<int, Unit> $allUnits
 */
#[Layout('components.layouts.app')]
class ItemForm extends Component
{
    use ConfirmsActions, WarnsBeforeLeaving;

    #[Locked]
    public ?string $productId = null;

    /** Stock has moved for this item: customer, base unit and tracking are fixed. */
    #[Locked]
    public bool $locked = false;

    public string $customerId = '';

    public string $sku = '';

    public string $nameEn = '';

    public string $nameAr = '';

    public string $nameFr = '';

    public string $baseUnitId = '';

    /**
     * Other units, keyed by a stable row key: "1 <unit_id> = <factor> base units".
     *
     * @var array<string, array{unit_id: string, factor: string}>
     */
    public array $units = [];

    #[Locked]
    public int $nextUnit = 1;

    public bool $trackExpiry = false;

    public bool $trackSerial = false;

    /** storage_status.code (Ambient, Chilled, …); '' = not set. */
    public string $storageStatus = '';

    /** Approval limit for this item, in base units; empty = use the customer / general limit. */
    public string $threshold = '';

    public bool $active = true;

    /** Why "Delete item" was refused (in use), shown next to the button; null = nothing to say. */
    public ?string $deleteBlocked = null;

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->authorize('update', $product);
            $this->load($product);
        } else {
            $this->authorize('create', Product::class);
            $this->baseUnitId = (string) Unit::where('code', 'PC')->value('id');
        }

        $this->appendBlankUnit();
        $this->rememberSavedState();
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function allUnits(): Collection
    {
        return Unit::orderBy('name')->get();
    }

    public function updated(string $property): void
    {
        if ($property === 'sku') {
            $this->sku = mb_strtoupper(trim($this->sku));
        }

        if (str_starts_with($property, 'units.')) {
            $this->appendBlankUnit();
        }

        if ($property === 'baseUnitId') {
            // A unit row that now matches the base unit must be re-checked.
            $this->resetValidation('units.*');
        }

        $this->validateOnly($property);
    }

    public function removeUnit(string $key): void
    {
        unset($this->units[$key]);
        $this->resetValidation('units.'.$key.'.*');
        $this->appendBlankUnit();
    }

    public function save(): void
    {
        $this->store(deactivationConfirmed: false);
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action === 'deactivate' && $this->productId === $id) {
            $this->store(deactivationConfirmed: true);
        }

        if ($action === 'delete' && $this->productId === $id) {
            $this->delete();
        }

        if ($action === 'leave') {
            $this->leaveTo($id);
        }
    }

    /**
     * "Delete item": asks first, unless something uses the item: then it says
     * where instead (CLAUDE.md "Confirmations").
     */
    public function askDelete(): void
    {
        $product = Product::findOrFail($this->productId);
        $this->authorize('delete', $product);

        if ($this->deleteBlocked = $this->whyNotDeletable($product)) {
            return;
        }

        $this->askToConfirm(
            'delete', (string) $product->id,
            __('confirm.delete.item.title', ['name' => $product->name()]),
            __('confirm.delete.item.body', ['name' => $product->name()]),
            __('confirm.delete.item.button'),
        );
    }

    private function delete(): void
    {
        $product = Product::findOrFail($this->productId);
        $this->authorize('delete', $product);

        // Checked again: a document may have used the item since the question.
        if ($this->deleteBlocked = $this->whyNotDeletable($product)) {
            return;
        }

        $name = $product->name();

        try {
            DB::transaction(function () use ($product) {
                Audit::record('product', $product->id, 'DELETE', app(ItemWriter::class)->snapshot($product), null);
                // Item approval limits have no foreign key; units and barcodes go with the item (ON DELETE CASCADE).
                DB::table('approval_threshold')->where('scope', 'PRODUCT')->where('scope_id', $product->id)->delete();
                $product->delete();
            });
        } catch (QueryException $e) {
            // Used by a row the checks above don't know about (foreign key violation).
            if ($e->getCode() !== '23503') {
                throw $e;
            }

            $this->deleteBlocked = __('confirm.blocked.item_has_records', ['name' => $name]);

            return;
        }

        Notify::success(__('items.deleted', ['name' => $name]));

        $this->allowLeaving();
        $this->redirectRoute('items', navigate: true);
    }

    /**
     * "Canned Beans 400g is used on 3 documents (Receipt, Issue) …", or null
     * when nothing uses the item. Every warehouse counts, not only the user's.
     */
    private function whyNotDeletable(Product $product): ?string
    {
        $documents = DB::table('stock_transaction_line')
            ->join('stock_transaction', 'stock_transaction.id', '=', 'stock_transaction_line.transaction_id')
            ->join('transaction_type', 'transaction_type.code', '=', 'stock_transaction.transaction_type')
            ->where('stock_transaction_line.product_id', $product->id)
            ->groupBy('transaction_type.code', 'transaction_type.display_order')
            ->orderBy('transaction_type.display_order')
            ->selectRaw('transaction_type.code, count(distinct stock_transaction.id) as documents')
            ->pluck('documents', 'code');

        if ($documents->isNotEmpty()) {
            $types = TransactionTypes::all();
            $count = (int) $documents->sum();

            return trans_choice('confirm.blocked.item_on_documents', $count, [
                'name' => $product->name(),
                'count' => Format::qty($count),
                'types' => $documents->keys()->map(fn (string $code) => isset($types[$code]) ? $types[$code]->label('singular') : $code)->implode(', '),
            ]);
        }

        $records = collect(['stock_movement', 'stock_balance', 'in_transit_balance', 'serial_number'])
            ->contains(fn (string $table) => DB::table($table)->where('product_id', $product->id)->exists());

        return $records ? __('confirm.blocked.item_has_records', ['name' => $product->name()]) : null;
    }

    /**
     * Saves the form; taking an item out of use asks first (CLAUDE.md "Confirmations").
     */
    private function store(bool $deactivationConfirmed): void
    {
        $product = $this->productId ? Product::findOrFail($this->productId) : new Product;
        $this->authorize($product->exists ? 'update' : 'create', $product->exists ? $product : Product::class);

        if ($product->exists && $product->hasMovements() && $this->changesLockedFields($product)) {
            // The page was stale or tampered with: the fields are disabled in the form.
            $this->load($product);
            $this->appendBlankUnit();
            $this->addError('baseUnitId', __('items.errors.locked'));

            return;
        }

        $this->sku = mb_strtoupper(trim($this->sku));
        $this->validate();

        $isNew = ! $product->exists;

        if (! $deactivationConfirmed && ! $isNew && $product->active && ! $this->active) {
            $this->askToConfirm(
                'deactivate', (string) $product->id,
                __('confirm.deactivate.item.title', ['name' => $product->name()]),
                __('confirm.deactivate.item.body', ['name' => $product->name()]),
                __('confirm.deactivate.item.button'),
            );

            return;
        }

        DB::transaction(fn () => app(ItemWriter::class)->save($product, [
            'customer_id' => $this->customerId,
            'sku' => $this->sku,
            'name_en' => trim($this->nameEn),
            'name_ar' => trim($this->nameAr) ?: null,
            'name_fr' => trim($this->nameFr) ?: null,
            'base_unit_id' => $this->baseUnitId,
            'track_expiry' => $this->trackExpiry,
            'track_serial' => $this->trackSerial,
            'storage_status' => $this->storageStatus !== '' ? $this->storageStatus : null,
            'approval_threshold_qty' => trim($this->threshold) === '' ? null : trim($this->threshold),
            'active' => $this->active,
        ], $this->filledUnits()));

        Notify::success(__($isNew ? 'items.added' : 'items.updated', ['name' => $product->name()]), route('items.edit', $product));

        $this->allowLeaving();
        $this->redirectRoute('items', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.items.form', [
            'customers' => Customer::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $this->customerId ?: null))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'baseUnit' => $this->allUnits->firstWhere('id', $this->baseUnitId),
            'preview' => $this->preview(),
            'title' => $this->productId ? __('items.edit', ['name' => $this->localName()]) : __('items.new'),
        ])->title($this->productId ? __('items.edit', ['name' => $this->localName()]) : __('items.new'));
    }

    protected function formState(): array
    {
        return [
            'customer' => $this->customerId,
            'code' => mb_strtoupper(trim($this->sku)),
            'names' => [trim($this->nameEn), trim($this->nameAr), trim($this->nameFr)],
            'base_unit' => $this->baseUnitId,
            'units' => array_map(fn (array $row) => [$row['unit_id'], trim($row['factor'])], $this->filledUnits()),
            'tracking' => [$this->trackExpiry, $this->trackSerial],
            'storage_status' => $this->storageStatus,
            'threshold' => trim($this->threshold),
            'active' => $this->active,
        ];
    }

    protected function leaveQuestion(): array
    {
        $name = $this->productId ? Product::whereKey($this->productId)->first()?->name() : null;

        return [
            'title' => $name === null ? __('confirm.discard.item.title_new') : __('confirm.discard.item.title', ['name' => $name]),
            'body' => __('confirm.discard.item.body'),
            'button' => __('confirm.discard.item.button'),
        ];
    }

    /**
     * The name in the current language, falling back to English (as Product::name()).
     */
    private function localName(): string
    {
        $name = match (app()->getLocale()) {
            'ar' => $this->nameAr,
            'fr' => $this->nameFr,
            default => $this->nameEn,
        };

        return trim($name) !== '' ? $name : $this->nameEn;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'customerId' => ['required', 'integer', Rule::exists('customer', 'id')],
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', $this->uniqueCode(...)],
            'nameEn' => ['required', 'string', 'max:200'],
            'nameAr' => ['nullable', 'string', 'max:200'],
            'nameFr' => ['nullable', 'string', 'max:200'],
            'baseUnitId' => ['required', 'integer', Rule::exists('unit', 'id')],
            'units' => ['array'],
            'units.*.unit_id' => [new Always($this->validUnitRow(...))],
            'units.*.factor' => [new Always($this->validFactor(...))],
            'trackExpiry' => ['boolean'],
            'trackSerial' => ['boolean', new Always(function (string $attribute, mixed $value, Closure $fail) {
                // serial_number has no expiry date, so a serial can't be tied to one.
                if ($value && $this->trackExpiry) {
                    $fail(__('items.errors.serial_and_expiry'));
                }
            })],
            'storageStatus' => ['nullable', 'string', Rule::in(array_keys(StorageStatuses::all()))],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,4'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'customerId.required' => __('items.errors.customer_required'),
            'customerId.*' => __('items.errors.customer_required'),
            'sku.required' => __('items.errors.code_required'),
            'sku.max' => __('settings.errors.too_long', ['max' => 50]),
            'sku.regex' => __('items.errors.code_format'),
            'nameEn.required' => __('items.errors.name_required'),
            'nameEn.max' => __('settings.errors.too_long', ['max' => 200]),
            'nameAr.max' => __('settings.errors.too_long', ['max' => 200]),
            'nameFr.max' => __('settings.errors.too_long', ['max' => 200]),
            'baseUnitId.*' => __('items.errors.base_unit_required'),
            'storageStatus.*' => __('items.errors.storage_status'),
            'threshold.*' => __('items.errors.threshold'),
        ];
    }

    /**
     * Codes are unique per customer (product UNIQUE (customer_id, sku)).
     */
    private function uniqueCode(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->customerId === '') {
            return;
        }

        $taken = Product::query()
            ->where('customer_id', $this->customerId)
            ->where('sku', $value)
            ->when($this->productId, fn ($query, string $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail(__('items.errors.code_taken'));
        }
    }

    /**
     * A filled row needs a unit that is not the base unit and not used on another row.
     */
    private function validUnitRow(string $attribute, mixed $value, Closure $fail): void
    {
        $key = explode('.', $attribute)[1];
        $row = $this->units[$key] ?? null;

        if ($row === null || $this->trackSerial || $this->isBlank($row)) {
            return;
        }

        if ($value === '' || ! $this->allUnits->contains('id', $value)) {
            $fail(__('items.errors.unit_required'));
        } elseif ($value === $this->baseUnitId) {
            $fail(__('items.errors.unit_is_base'));
        } elseif (collect($this->units)->except($key)->contains(fn (array $other) => $other['unit_id'] === $value)) {
            $fail(__('items.errors.unit_twice'));
        }
    }

    /**
     * The same rule as trg_validate_product_unit: more than 1 base unit (NUMERIC(18,6)).
     */
    private function validFactor(string $attribute, mixed $value, Closure $fail): void
    {
        $key = explode('.', $attribute)[1];
        $row = $this->units[$key] ?? null;

        if ($row === null || $this->trackSerial || $this->isBlank($row)) {
            return;
        }

        $value = trim((string) $value);

        if ($value === '') {
            $fail(__('items.errors.factor_required'));
        } elseif (! is_numeric($value) || preg_match('/\.\d{7,}$/', $value) || (float) $value >= 1e12) {
            $fail(__('items.errors.factor_number'));
        } elseif ((float) $value <= 1) {
            $fail(__('items.errors.factor_more_than_one'));
        }
    }

    /**
     * "Example: 493 Piece = 1 Pallet + 1 Box + 1 Piece", once the rows are valid.
     */
    private function preview(): ?string
    {
        $base = $this->allUnits->firstWhere('id', $this->baseUnitId);
        $rows = $this->trackSerial ? [] : $this->filledUnits();

        if ($base === null || $rows === []) {
            return null;
        }

        $factors = [];
        foreach ($rows as $row) {
            $unit = $this->allUnits->firstWhere('id', $row['unit_id']);

            if ($unit === null || $unit->id === $base->id || ! is_numeric($row['factor']) || (float) $row['factor'] <= 1 || isset($factors[$unit->code])) {
                return null;
            }

            $factors[$unit->code] = (float) $row['factor'];
        }

        $converter = new UnitConverter($factors, $base->code);
        $sample = array_sum($factors) + 1;

        return __('items.units.preview', [
            'qty' => Format::qtyUnit($sample, $base->code),
            'breakdown' => $converter->breakdown($sample),
        ]);
    }

    /**
     * @return list<array{unit_id: string, factor: string}>
     */
    private function filledUnits(): array
    {
        return array_values(array_filter($this->units, fn (array $row) => ! $this->isBlank($row)));
    }

    /**
     * @param  array{unit_id: string, factor: string}  $row
     */
    private function isBlank(array $row): bool
    {
        return $row['unit_id'] === '' && trim($row['factor']) === '';
    }

    private function appendBlankUnit(): void
    {
        $last = end($this->units);

        if ($last === false || ! $this->isBlank($last)) {
            $this->units['u'.$this->nextUnit++] = ['unit_id' => '', 'factor' => ''];
        }
    }

    private function changesLockedFields(Product $product): bool
    {
        return $product->customer_id !== $this->customerId
            || $product->base_unit_id !== $this->baseUnitId
            || $product->track_expiry !== $this->trackExpiry
            || $product->track_serial !== $this->trackSerial;
    }

    private function load(Product $product): void
    {
        $product->load('units');

        $this->productId = $product->id;
        $this->locked = $product->hasMovements();
        $this->customerId = $product->customer_id;
        $this->sku = $product->sku;
        $this->nameEn = $product->name_en;
        $this->nameAr = (string) $product->name_ar;
        $this->nameFr = (string) $product->name_fr;
        $this->baseUnitId = $product->base_unit_id;
        $this->trackExpiry = $product->track_expiry;
        $this->trackSerial = $product->track_serial;
        $this->storageStatus = (string) $product->storage_status;
        $this->threshold = $product->approval_threshold_qty === null ? '' : rtrim(rtrim($product->approval_threshold_qty, '0'), '.');
        $this->active = $product->active;
        $this->units = [];

        foreach ($product->units->where('unit_id', '!=', $product->base_unit_id)->sortBy('factor_to_base') as $row) {
            $this->units['u'.$this->nextUnit++] = [
                'unit_id' => $row->unit_id,
                'factor' => rtrim(rtrim(number_format($row->factor_to_base, 6, '.', ''), '0'), '.'),
            ];
        }
    }
}
