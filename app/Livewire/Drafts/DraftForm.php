<?php

namespace App\Livewire\Drafts;

use App\Livewire\Concerns\ConfirmsActions;
use App\Livewire\Concerns\WarnsBeforeLeaving;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\User;
use App\Services\Stock\Availability;
use App\Services\Stock\DraftInput;
use App\Services\Stock\DraftLineInput;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\ItemCatalog;
use App\Services\Stock\PostingService;
use App\Services\Stock\UnitConverter;
use App\Support\CompanyTime;
use App\Support\Drafts\DraftDocuments;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Format;
use App\Support\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New / edit form of a draft receipt or order. Like the transaction form
 * (customer first, rows appended as quantities are filled, the empty last row
 * never saved, Warehouse in the header starting on the user's default, lines
 * cleared after asking when it changes), plus a Branch. Saving touches no stock.
 *
 * Receipt rows take what Receive takes (expiry date typed, serials typed);
 * order rows what a Delivery note takes, in the draft's warehouse (expiry date picked,
 * nearest first; serials picked; "Available" there and no more).
 *
 * Editing, a user who may confirm drafts also gets "Save and confirm": the
 * changes and the confirmation are saved together, or neither.
 */
#[Layout('components.layouts.app')]
class DraftForm extends Component
{
    use ConfirmsActions, WarnsBeforeLeaving;

    /** transaction_type.code the draft creates, from the route. */
    #[Locked]
    public string $type = '';

    /** The draft being edited (doc_no); '' for a new one. */
    #[Locked]
    public string $reference = '';

    /** stock_draft.updated_at as the form opened it: a draft changed since is not overwritten. */
    #[Locked]
    public string $version = '';

    /** Changed only through chooseCustomer() (lines are cleared on a change). */
    #[Locked]
    public string $customerId = '';

    public ?string $pendingCustomer = null;

    public int $customerVersion = 0;

    public string $branchId = '';

    /**
     * Where the stock comes in / goes out: one of the user's warehouses,
     * starting on their default. Changed only through chooseWarehouse().
     */
    #[Locked]
    public string $warehouseId = '';

    /** Warehouse the user picked while lines exist; waits for confirmation. */
    public ?string $pendingWarehouse = null;

    /** Bumped to redraw the warehouse dropdown when a change is put on hold. */
    public int $warehouseVersion = 0;

    public string $date = '';

    public string $note = '';

    /** Orders (DraftUi::hasDelivery()): when to deliver, starting on the order date. */
    public string $deliveryDate = '';

    /** Orders: URGENT / NORMAL / LOW. */
    public string $priority = '';

    /**
     * Rows keyed by a stable key ("r1", "r2", ...). `serials`: typed (receipt);
     * `picked`: chosen from stock in the draft's warehouse (order).
     *
     * @var array<string, array{item: ?string, unit: string, qty: string, expiry: string, serials: string, picked: list<string>}>
     */
    public array $lines = [];

    #[Locked]
    public int $nextLine = 1;

    /** Submission token (stock_draft.client_request_id): saving twice creates one draft. */
    #[Locked]
    public string $requestId = '';

    public bool $showAll = false;

    /** @var array<string, array<string, mixed>|null> per-request cache: product => catalog item */
    private array $items = [];

    /** @var array<string, string>|null per-request cache: the user's warehouses, id => name */
    private ?array $warehouseNames = null;

    public function mount(string $type, ?string $reference = null): void
    {
        $kind = DraftTypes::get($type);
        $this->type = $kind->code;
        $user = $this->user();

        if ($reference !== null) {
            $draft = DraftDocuments::find($kind, $reference) ?? abort(404);
            $this->authorize('update', $draft['model']);
            $this->fill([
                'reference' => $draft['reference'],
                'version' => (string) $draft['version'],
                'customerId' => $draft['customer_id'],
                'branchId' => $draft['branch_id'],
                'warehouseId' => (string) $draft['warehouse_id'],
                'date' => $draft['date']->toDateString(),
                'note' => (string) $draft['note'],
                'deliveryDate' => $draft['delivery_date']?->toDateString() ?? '',
                'priority' => (string) $draft['priority'],
            ]);

            foreach ($draft['lines'] as $line) {
                $this->lines['r'.$this->nextLine++] = [
                    'item' => $line['product_id'],
                    'unit' => $line['unit'],
                    'qty' => (string) (int) $line['qty'],
                    'expiry' => (string) $line['expiry'],
                    'serials' => $kind->receives() ? implode("\n", $line['serials']) : '',
                    'picked' => $kind->receives() ? [] : $line['serials'],
                ];
            }
        } else {
            Gate::authorize($kind->createPermission());
            $this->date = CompanyTime::today()->toDateString();
            $this->requestId = (string) Str::uuid();
            $this->warehouseId = (string) $user->defaultWarehouseId();

            if ($kind->hasDelivery()) {
                $this->deliveryDate = $this->date;
                $this->priority = DraftTypes::defaultPriority();
            }

            // A customer user works for their own customer only.
            if ($user->isCustomerUser()) {
                $this->customerId = (string) $user->customer_id;
                $this->suggestBranch();
            }
        }

        $this->addBlankLine();
        $this->rememberSavedState();
    }

    // Customer and branch

    public function chooseCustomer(string $id): void
    {
        if ($id === $this->customerId || ! array_key_exists($id, $this->customerOptions()) || $this->user()->isCustomerUser()) {
            $this->customerVersion++;

            return;
        }

        if ($this->customerId !== '' && $this->filledLines() !== []) {
            $this->pendingCustomer = $id;
            $this->customerVersion++;

            return;
        }

        $this->applyCustomer($id);
    }

    public function confirmCustomerChange(): void
    {
        if ($this->pendingCustomer !== null) {
            $this->applyCustomer($this->pendingCustomer);
        }
    }

    public function keepCustomer(): void
    {
        $this->pendingCustomer = null;
        $this->customerVersion++;
    }

    // Warehouse

    /**
     * Another warehouse: what is in stock, expiry dates and serials differ
     * there, so lines already entered are cleared (after asking).
     */
    public function chooseWarehouse(string $id): void
    {
        if ($id === $this->warehouseId || ! array_key_exists($id, $this->warehouseOptions())) {
            $this->warehouseVersion++;

            return;
        }

        if ($this->filledLines() !== []) {
            $this->pendingWarehouse = $id;
            $this->warehouseVersion++;

            return;
        }

        $this->applyWarehouse($id);
    }

    public function confirmWarehouseChange(): void
    {
        if ($this->pendingWarehouse !== null) {
            $this->applyWarehouse($this->pendingWarehouse);
        }
    }

    public function keepWarehouse(): void
    {
        $this->pendingWarehouse = null;
        $this->warehouseVersion++;
    }

    private function applyWarehouse(string $id): void
    {
        $this->warehouseId = $id;
        $this->pendingWarehouse = null;
        $this->warehouseVersion++;
        $this->showAll = false;
        $this->items = [];
        $this->lines = [];
        $this->addBlankLine();
    }

    // Lines

    public function updated(): void
    {
        $this->resetErrorBag();
    }

    /** An order is never delivered before its date: the delivery date follows a later order date. */
    public function updatedDate(): void
    {
        if ($this->kind()->hasDelivery() && strtotime($this->date) && ($this->deliveryDate === '' || $this->deliveryDate < $this->date)) {
            $this->deliveryDate = $this->date;
        }
    }

    public function updatedLines(mixed $value, string $path): void
    {
        [$key, $field] = array_pad(explode('.', $path, 2), 2, null);
        $this->resetErrorBag();

        if (! isset($this->lines[$key])) {
            return;
        }

        if ($field === 'item') {
            $item = $this->item($this->lines[$key]);
            $this->lines[$key]['unit'] = $item['base'] ?? '';
            $this->lines[$key]['serials'] = '';
            $this->lines[$key]['picked'] = [];
            $this->lines[$key]['expiry'] = '';

            // Stock going out of an expiry item: suggest the nearest expiry date in the draft's warehouse.
            if ($item !== null && $item['expiry'] && ! $this->kind()->receives()) {
                $this->lines[$key]['expiry'] = $this->pickableExpiries($item)[0]['expiry'] ?? '';
            }

            if ($item !== null && $item['serial']) {
                $this->lines[$key]['qty'] = '';
            }
        }

        // Serial items: the quantity is the number of serials.
        if ($field === 'serials' || str_starts_with((string) $field, 'picked')) {
            $count = $this->kind()->receives() ? count($this->serialList($this->lines[$key]['serials'])) : count($this->lines[$key]['picked']);
            $this->lines[$key]['qty'] = $count > 0 ? (string) $count : '';
        }

        $this->appendBlankIfNeeded();
    }

    public function deleteLine(string $key): void
    {
        unset($this->lines[$key]);

        if ($this->lines === []) {
            $this->addBlankLine();
        }

        $this->appendBlankIfNeeded();
    }

    // Saving

    public function save(): void
    {
        $this->submit(confirm: false);
    }

    /**
     * Editing, for a user who may confirm: saves the changes and confirms the
     * draft in one go (CLAUDE.md: confirming, like approving, asks nothing).
     */
    public function saveAndConfirm(): void
    {
        $this->submit(confirm: true);
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action === 'leave') {
            $this->leaveTo($id);
        }
    }

    private function submit(bool $confirm): void
    {
        $kind = $this->kind();
        $user = $this->user();
        $draft = $this->reference === '' ? null : (DraftDocuments::find($kind, $this->reference) ?? abort(404));

        if ($draft === null) {
            Gate::authorize($kind->createPermission());
            abort_if($confirm, 403);
        } else {
            $this->authorize($confirm ? 'confirm' : 'update', $draft['model']);
        }

        if (! $this->check()['valid']) {
            $this->showAll = true;
            $this->notifyInvalid();

            return;
        }

        $service = app(DraftService::class);

        try {
            if ($confirm) {
                $documents = $service->confirm($draft['model'], $user, $this->input());
            } else {
                $saved = $service->save($this->input(), $user, $draft['model'] ?? null);
            }
        } catch (StockException $e) {
            $row = $confirm ? $service->rowOf($e->row) : $e->row;

            // Rule 12: the repeated line and the line it repeats, both in red.
            if ($e instanceof DuplicateLine) {
                $other = $confirm ? ($service->rowOf($e->otherRow) ?? $e->otherRow) : $e->otherRow;
                $row ??= $e->row;

                if (isset($this->lines[$other])) {
                    $this->addError('lines.'.$other.'.item', $e->getMessage());
                }
            }

            if ($row !== null && isset($this->lines[$row])) {
                $this->addError('lines.'.$row.'.'.($e->field ?? 'qty'), $e->getMessage());
            } elseif ($row === null && in_array($e->field, ['customer', 'branch', 'warehouse', 'date', 'delivery_date', 'priority', 'reference'], true)) {
                $this->addError($e->field, $e->getMessage());
            } else {
                $this->addError('posting', $e->getMessage());
            }

            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $this->allowLeaving();
        $reference = $confirm ? $this->reference : $saved->doc_no;
        $url = $kind->route('show', $reference);

        if ($confirm) {
            Notify::success(DraftPage::confirmedMessage($kind, $reference, $documents), $url);
        } else {
            Notify::success(__($draft === null ? 'drafts.feedback.saved' : 'drafts.feedback.updated', ['type' => $kind->label('singular'), 'reference' => $reference]), $url);
        }

        $this->redirect($url, navigate: true);
    }

    private function notifyInvalid(): void
    {
        $check = $this->check();
        $rows = count(array_filter($check['rows'], fn (array $row) => ($row['errors'] ?? []) !== []));

        Notify::error($rows > 0
            ? trans_choice('feedback.rows_need_attention', $rows, ['count' => Format::qty($rows)])
            : ($check['problems'][0] ?? trans_choice('feedback.fields_need_attention', 1, ['count' => Format::qty(1)])), on: $this);
    }

    private function input(): DraftInput
    {
        $lines = [];

        foreach ($this->filledLines() as $key => $line) {
            $lines[$key] = new DraftLineInput(
                productId: (string) $line['item'],
                unit: $line['unit'],
                qty: trim($line['qty']),
                expiry: $line['expiry'] ?: null,
                serials: $this->kind()->receives() ? $this->serialList($line['serials']) : $line['picked'],
            );
        }

        return new DraftInput(
            type: $this->type,
            customerId: $this->customerId,
            branchId: $this->branchId,
            warehouseId: $this->warehouseId,
            date: CarbonImmutable::parse($this->date),
            note: $this->note,
            lines: $lines,
            requestId: $this->requestId ?: null,
            version: $this->version ?: null,
            deliveryDate: $this->kind()->hasDelivery() && strtotime($this->deliveryDate) ? CarbonImmutable::parse($this->deliveryDate) : null,
            priority: $this->kind()->hasDelivery() ? ($this->priority ?: null) : null,
        );
    }

    public function render(): View
    {
        $kind = $this->kind();
        $title = $this->reference === '' ? $kind->label('new') : __('drafts.form.edit_title', ['reference' => $this->reference]);

        return view('livewire.drafts.form', [
            'kind' => $kind,
            'title' => $title,
            'check' => $this->check(),
            'customers' => $this->customerOptions(),
            'branches' => $this->branchOptions(),
            'warehouses' => $this->warehouseOptions(),
            // One warehouse: nothing to choose (unless the draft's isn't one of the user's).
            'showWarehouse' => count($this->warehouseOptions()) > 1 || ! $this->warehouseOk(),
            'customerUser' => $this->user()->isCustomerUser(),
            'priorities' => DraftTypes::priorityOptions(),
            'canConfirm' => $this->reference !== '' && Gate::allows($kind->confirmPermission()),
        ])->title($title);
    }

    // Checking

    /**
     * Every row's numbers, hints and errors, header errors, the footer summary
     * and whether Save is allowed.
     *
     * @return array{rows: array<string, array<string, mixed>>, header: array<string, string>, problems: list<string>, valid: bool, summary: string, filled: int}
     */
    protected function check(): array
    {
        $rows = [];
        $problems = [];
        $used = [];
        $baseUnits = [];
        $filled = 0;
        $total = 0.0;
        $lastKey = array_key_last($this->lines);
        $number = 0;
        $this->loadItems();

        foreach ($this->lines as $key => $line) {
            $number++;

            if ($this->isBlank($line)) {
                $rows[$key] = ['blank' => true, 'errors' => []];

                continue;
            }

            $row = $this->checkLine($line, $used);
            $row['shown'] = $this->showAll || $key !== $lastKey
                ? $row['errors']
                : array_diff_key($row['errors'], array_flip($row['missing']));

            // Refusals from the last save stay until the form changes.
            foreach (['item', 'unit', 'qty', 'expiry', 'serials'] as $field) {
                if ($message = $this->getErrorBag()->first('lines.'.$key.'.'.$field)) {
                    $row['errors'][$field] = $message;
                    $row['shown'][$field] = $message;
                }
            }

            foreach ($row['errors'] as $message) {
                $problems[] = __('transactions.form.row_problem', ['row' => Format::qty($number), 'problem' => $message]);
            }

            if ($row['item'] !== null) {
                $filled++;
            }

            // Services are not stock: the footer total adds up stock items only.
            if ($row['item'] !== null && ! $row['item']['service']) {
                $baseUnits[] = $row['item']['base'];
                $total += $row['base'] ?? 0;
            }

            $rows[$key] = $row;
        }

        $header = $this->checkHeader();

        if ($this->customerId === '') {
            array_unshift($problems, __('transactions.form.choose_customer'));
        } elseif ($filled === 0) {
            $problems[] = __('transactions.form.add_a_line');
        }

        foreach ($header['missing'] as $message) {
            $problems[] = $message;
        }

        if ($message = $this->getErrorBag()->first('posting')) {
            array_unshift($problems, $message);
        }

        return [
            'rows' => $rows,
            'header' => $header['errors'] + ($this->showAll ? $header['missing'] : []),
            'problems' => array_merge(array_values($header['errors']), $problems),
            'valid' => $header['errors'] === [] && $problems === [],
            'summary' => Format::itemsTotal($filled, $total, $baseUnits),
            'filled' => $filled,
        ];
    }

    /**
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, serials: string, picked: list<string>}  $line
     * @param  array<string, float>  $used  base quantity taken by earlier rows (per item and expiry date), serials seen
     * @return array<string, mixed>
     */
    private function checkLine(array $line, array &$used): array
    {
        $receives = $this->kind()->receives();
        $item = $this->item($line);
        $factor = $item['units'][$line['unit']] ?? null;
        $converter = $item ? new UnitConverter($item['units'], $item['base']) : null;
        $errors = [];
        $missing = [];
        $qty = trim($line['qty']);
        $base = null;
        $row = ['blank' => false, 'item' => $item, 'factor' => $factor];

        // Without a warehouse nothing can be checked: the header says so.
        if ($this->warehouseOk() && ($line['item'] === null || $item === null)) {
            $errors['item'] = __('transactions.form.errors.choose_item');
            $missing[] = 'item';
        } elseif ($item !== null && $factor === null) {
            $errors['unit'] = __('transactions.form.errors.choose_unit');
        }

        if ($item !== null && $item['serial']) {
            [$serialErrors, $options] = $this->checkSerials($item, $line, $used);
            $row['serial_options'] = $options;

            if ($serialErrors !== []) {
                $errors['serials'] = $serialErrors[0];
            }

            if ($qty === '') {
                $errors['serials'] ??= __($receives ? 'transactions.form.errors.serials_required' : 'transactions.form.errors.serials_pick');
                $missing[] = 'serials';
            }
        }

        $needsExpiry = $item !== null && $item['expiry'];

        if ($needsExpiry && ! $receives) {
            $row['expiry_options'] = $this->pickableExpiries($item);

            if ($line['expiry'] === '') {
                $errors['expiry'] = __('transactions.form.errors.expiry_choose');
                $missing[] = 'expiry';
            } elseif (collect($row['expiry_options'])->firstWhere('expiry', $line['expiry']) === null) {
                $errors['expiry'] = __('transactions.form.errors.expiry_not_here');
            }
        }

        if ($needsExpiry && $receives) {
            if ($line['expiry'] === '') {
                $errors['expiry'] = __('transactions.form.errors.expiry_required');
                $missing[] = 'expiry';
            } elseif (! strtotime($line['expiry'])) {
                $errors['expiry'] = __('transactions.form.errors.expiry_invalid');
            }

            $row['expiry_past'] = ! isset($errors['expiry']) && $line['expiry'] < CompanyTime::today()->toDateString();
        }

        // In stock in the draft's warehouse (of the chosen expiry date when going out), in base units.
        $perExpiry = $needsExpiry && ! $receives;
        $stockKey = ($item['id'] ?? '').($perExpiry ? '|'.$line['expiry'] : '');
        $inStock = $item === null ? 0.0 : ($perExpiry && $line['expiry'] !== ''
            ? (float) (collect($item['expiries'])->firstWhere('expiry', $line['expiry'])['qty'] ?? 0)
            : $item['available']);
        $remaining = max($inStock - ($used[$stockKey] ?? 0), 0);

        if ($qty === '') {
            if (! ($item['serial'] ?? false)) {
                $errors['qty'] = __('transactions.form.errors.qty_required');
                $missing[] = 'qty';
            }
        } elseif (! PostingService::whole($qty)) {
            $errors['qty'] = __('transactions.form.errors.number');
        } elseif ((float) $qty <= 0) {
            $errors['qty'] = __('transactions.form.errors.more_than_zero');
        } elseif (PostingService::tooLarge($qty)) {
            $errors['qty'] = __('transactions.form.errors.too_large', ['max' => Format::qty(PostingService::MAX_QTY)]);
        } elseif ($converter !== null && $factor !== null) {
            $base = $converter->toBase($qty, $line['unit']);

            // A service has no stock: never limited by it.
            if (! $receives && ! $item['service'] && $base > $remaining + 1e-9) {
                $errors['qty'] = __('transactions.form.errors.not_enough', ['qty' => $converter->breakdown($remaining, [$line['unit']])]);
            }

            $used[$stockKey] = ($used[$stockKey] ?? 0) + $base;
        }

        return $row + [
            'errors' => $errors,
            'missing' => $missing,
            'base' => $base,
            'available_text' => $converter && $factor && ! $item['service'] ? $converter->breakdown($remaining, [$line['unit']]) : null,
            'conversion' => $base !== null && $factor > 1 ? Format::qtyUnit($base, $item['base']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, serials: string, picked: list<string>}  $line
     * @param  array<string, float>  $used
     * @return array{0: list<string>, 1: list<string>} errors, and the serials that can be picked
     */
    private function checkSerials(array $item, array $line, array &$used): array
    {
        $errors = [];

        if ($this->kind()->receives()) {
            $serials = $this->serialList($line['serials']);

            foreach ($serials as $serial) {
                if (isset($used['serial|'.$item['id'].'|'.$serial])) {
                    $errors[] = __('stock.errors.serial_twice', ['serial' => $serial]);
                }

                $used['serial|'.$item['id'].'|'.$serial] = 1.0;
            }

            $inStock = $serials === [] ? null : DB::table('serial_number')
                ->where('product_id', $item['id'])
                ->whereIn('serial_no', $serials)
                ->whereIn('status', ['IN_STOCK', 'IN_TRANSIT'])
                ->value('serial_no');

            if ($inStock !== null) {
                $errors[] = __('stock.errors.serial_in_stock', ['serial' => $inStock]);
            }

            return [$errors, []];
        }

        $options = app(Availability::class)->serials($this->warehouseId, $item['id']);

        foreach ($line['picked'] as $serial) {
            if (! in_array($serial, $options, true)) {
                $errors[] = __('stock.errors.serial_not_in_stock', ['serial' => $serial]);
            }

            if (isset($used['serial|'.$item['id'].'|'.$serial])) {
                $errors[] = __('stock.errors.serial_twice', ['serial' => $serial]);
            }

            $used['serial|'.$item['id'].'|'.$serial] = 1.0;
        }

        return [$errors, $options];
    }

    /**
     * @return array{errors: array<string, string>, missing: array<string, string>}
     */
    private function checkHeader(): array
    {
        $errors = [];
        $missing = [];

        if ($this->customerId === '') {
            return ['errors' => [], 'missing' => []];
        }

        if ($this->warehouseId === '') {
            $missing['warehouse'] = __('drafts.errors.choose_warehouse');
        } elseif (! $this->warehouseOk()) {
            $errors['warehouse'] = __('drafts.errors.choose_warehouse');
        }

        if ($this->branchId === '') {
            $missing['branch'] = __('drafts.errors.branch_required');
        } elseif (! array_key_exists($this->branchId, $this->branchOptions())) {
            $errors['branch'] = __('drafts.errors.branch_required');
        }

        if ($this->date === '') {
            $missing['date'] = __('transactions.form.errors.date_required');
        } elseif (! strtotime($this->date)) {
            $errors['date'] = __('transactions.form.errors.date_invalid');
        } elseif (CarbonImmutable::parse($this->date)->toDateString() > CompanyTime::today()->toDateString()) {
            $errors['date'] = __('transactions.form.errors.date_future');
        }

        if ($this->kind()->hasDelivery()) {
            if ($this->deliveryDate === '') {
                $missing['delivery_date'] = __('drafts.errors.delivery_date_required');
            } elseif (! strtotime($this->deliveryDate)) {
                $errors['delivery_date'] = __('transactions.form.errors.date_invalid');
            } elseif (strtotime($this->date) && $this->deliveryDate < CarbonImmutable::parse($this->date)->toDateString()) {
                $errors['delivery_date'] = __('drafts.errors.delivery_date_before');
            }

            if (! array_key_exists($this->priority, DraftTypes::priorityOptions())) {
                $missing['priority'] = __('drafts.errors.priority_required');
            }
        }

        if (mb_strlen($this->note) > DraftService::MAX_NOTE) {
            $errors['reference'] = __('settings.errors.too_long', ['max' => DraftService::MAX_NOTE]);
        }

        // Refusals from the last save about the header.
        foreach (['customer', 'branch', 'warehouse', 'date', 'delivery_date', 'priority', 'reference'] as $field) {
            if ($message = $this->getErrorBag()->first($field)) {
                $errors[$field] = $message;
            }
        }

        return compact('errors', 'missing');
    }

    /**
     * One of the chosen customer's active items with its stock in the draft's
     * warehouse (memoized per request). Going out, expired stock doesn't count.
     *
     * @param  array{item: ?string}  $line
     * @return array<string, mixed>|null
     */
    private function item(array $line): ?array
    {
        if ($line['item'] === null || $this->customerId === '' || ! $this->warehouseOk()) {
            return null;
        }

        if (! array_key_exists($line['item'], $this->items)) {
            $this->loadItems([$line]);
        }

        return $this->items[$line['item']];
    }

    /**
     * Loads the items of all rows (or of $lines) not loaded yet, in one query
     * instead of one per row; unknown items are kept as null.
     *
     * @param  list<array{item: ?string}>|null  $lines
     */
    private function loadItems(?array $lines = null): void
    {
        if ($this->customerId === '' || ! $this->warehouseOk()) {
            return;
        }

        $missing = [];

        foreach ($lines ?? $this->lines as $line) {
            if ($line['item'] !== null && $line['item'] !== '' && ! array_key_exists($line['item'], $this->items)) {
                $missing[$line['item']] = $line['item'];
            }
        }

        if ($missing === []) {
            return;
        }

        $found = ItemCatalog::items($this->customerId, array_values($missing), $this->warehouseId, excludeExpired: ! $this->kind()->receives(), withServices: $this->kind()->type->allowsServices());

        foreach ($missing as $id) {
            $this->items[$id] = $found[$id] ?? null;
        }
    }

    /**
     * Expiry dates of an item that may go out, nearest first (never expired ones).
     *
     * @param  array<string, mixed>  $item
     * @return list<array{expiry: string, qty: float, expired: bool}>
     */
    private function pickableExpiries(array $item): array
    {
        return array_values(array_filter($item['expiries'], fn (array $lot) => ! $lot['expired']));
    }

    /**
     * @return list<string>
     */
    private function serialList(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text) ?: []), fn (string $serial) => $serial !== ''));
    }

    // Options

    /**
     * Active customers, id => name (a customer user: their own).
     *
     * @return array<string, string>
     */
    private function customerOptions(): array
    {
        return Customer::where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Active branches of the chosen customer the user may use, id => name.
     *
     * @return array<string, string>
     */
    private function branchOptions(): array
    {
        if ($this->customerId === '') {
            return [];
        }

        $user = $this->user();

        return CustomerBranch::where('customer_id', $this->customerId)
            ->where('active', true)
            ->when($user->branchIds() !== [], fn ($query) => $query->whereIn('id', $user->branchIds()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The user's active warehouses (user_warehouse), id => name.
     *
     * @return array<string, string>
     */
    private function warehouseOptions(): array
    {
        return $this->warehouseNames ??= $this->user()->assignedWarehouses()->pluck('name', 'id')->all();
    }

    /**
     * The chosen warehouse is one of the user's.
     */
    private function warehouseOk(): bool
    {
        return array_key_exists($this->warehouseId, $this->warehouseOptions());
    }

    /**
     * Only one branch to choose from: chosen already.
     */
    private function suggestBranch(): void
    {
        $branches = $this->branchOptions();
        $this->branchId = count($branches) === 1 ? (string) array_key_first($branches) : '';
    }

    private function applyCustomer(string $id): void
    {
        $this->customerId = $id;
        $this->pendingCustomer = null;
        $this->customerVersion++;
        $this->showAll = false;
        $this->suggestBranch();
        $this->lines = [];
        $this->addBlankLine();
    }

    // Rows

    /**
     * @return array<string, array{item: ?string, unit: string, qty: string, expiry: string, serials: string, picked: list<string>}>
     */
    private function filledLines(): array
    {
        return array_filter($this->lines, fn (array $line) => ! $this->isBlank($line));
    }

    /**
     * A row is blank until something is filled.
     *
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, serials: string, picked: list<string>}  $line
     */
    private function isBlank(array $line): bool
    {
        return $line['item'] === null && trim($line['qty']) === '' && $line['expiry'] === ''
            && trim($line['serials']) === '' && $line['picked'] === [];
    }

    private function appendBlankIfNeeded(): void
    {
        $last = end($this->lines);

        if ($last === false || trim($last['qty']) !== '') {
            $this->addBlankLine();
        }
    }

    private function addBlankLine(): void
    {
        $this->lines['r'.$this->nextLine++] = ['item' => null, 'unit' => '', 'qty' => '', 'expiry' => '', 'serials' => '', 'picked' => []];
    }

    // Leaving

    protected function formState(): array
    {
        return [
            'customer' => $this->customerId,
            'branch' => $this->branchId,
            'warehouse' => $this->warehouseId,
            'date' => $this->date,
            'note' => trim($this->note),
            'delivery_date' => $this->deliveryDate,
            'priority' => $this->priority,
            'lines' => array_values(array_map(fn (array $line) => [
                $line['item'], $line['unit'], trim($line['qty']), $line['expiry'], trim($line['serials']), $line['picked'],
            ], $this->filledLines())),
        ];
    }

    protected function leaveQuestion(): array
    {
        return [
            'title' => $this->reference === ''
                ? __('drafts.leave.title_new', ['type' => $this->kind()->label('singular')])
                : __('drafts.leave.title', ['reference' => $this->reference]),
            'body' => __('confirm.discard.item.body'),
            'button' => __('confirm.discard.item.button'),
        ];
    }

    private function kind(): DraftUi
    {
        return DraftTypes::get($this->type);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
