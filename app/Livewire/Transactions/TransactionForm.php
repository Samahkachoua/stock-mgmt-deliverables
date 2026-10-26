<?php

namespace App\Livewire\Transactions;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\Customer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\ApprovalThresholds;
use App\Services\Stock\Availability;
use App\Services\Stock\Document;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\Exceptions\DuplicateLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\ItemCatalog;
use App\Services\Stock\PostingService;
use App\Services\Stock\UnitConverter;
use App\Support\Batches;
use App\Support\CompanyTime;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "New <type>" form shared by every transaction type (CLAUDE.md "Screen
 * patterns" 2): header card, lines grid, sticky footer. Customer comes first;
 * everything else waits for it. A new empty row appears as soon as the last
 * row gets a quantity; that empty last row is never validated or saved.
 *
 * Saving goes through PostingService; its refusals (not enough stock, serial
 * problems...) are shown under the field they concern. Damage and Adjustment
 * lines at or above their approval limit (item → customer → global) turn Save
 * into "Send for approval".
 *
 * Serial-numbered items take serials instead of a quantity: typed one per line
 * when stock comes in, picked from what is in stock here when it goes out, and
 * on a count all ticked at first (the user unticks the pieces not found).
 */
#[Layout('components.layouts.app')]
class TransactionForm extends Component
{
    use ConfirmsActions;

    /** transaction_type.code, from the route. */
    #[Locked]
    public string $type = '';

    /** Changed only through chooseCustomer() (lines are cleared on a change). */
    #[Locked]
    public string $customerId = '';

    /** Customer the user picked while lines exist; waits for confirmation. */
    public ?string $pendingCustomer = null;

    /** Bumped to redraw the customer dropdown when a change is put on hold. */
    public int $customerVersion = 0;

    /**
     * The warehouse the document is for (a Transfer's "From"): one of the
     * user's, starting on their default. Changed only through chooseWarehouse().
     */
    #[Locked]
    public string $warehouseId = '';

    /** Warehouse the user picked while lines exist; waits for confirmation. */
    public ?string $pendingWarehouse = null;

    /** Bumped to redraw the warehouse dropdown when a change is put on hold. */
    public int $warehouseVersion = 0;

    public string $date = '';

    public string $reference = '';

    public string $reason = '';

    public string $toWarehouse = '';

    /**
     * Rows keyed by a stable key ("r1", "r2", ...), so a deleted row never shifts
     * another row's bindings.
     *
     * `serials`: serial numbers typed one per line (stock coming in);
     * `picked`: serial numbers chosen from stock (stock going out).
     *
     * @var array<string, array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}>
     */
    public array $lines = [];

    #[Locked]
    public int $nextLine = 1;

    /**
     * Submission token of this form (stock_transaction.client_request_id): saving
     * twice with it creates one document (CLAUDE.md "Loading states").
     */
    #[Locked]
    public string $requestId = '';

    /** Set when Save is attempted, so every missing value is shown. */
    public bool $showAll = false;

    /** @var array<string, array<string, mixed>|null> per-request cache: product id => catalog item */
    private array $items = [];

    /** @var array<string, string>|null per-request cache: customer id => name */
    private ?array $customers = null;

    /** @var array<string, string>|null per-request cache: the user's warehouses, id => name */
    private ?array $warehouses = null;

    /** Per-request cache of the chosen warehouse. */
    private ?Warehouse $warehouse = null;

    public function mount(string $type): void
    {
        $ui = TransactionTypes::get($type);
        Gate::authorize($ui->createPermission());

        $this->type = $ui->code;
        $user = Auth::user();
        $this->warehouseId = $user instanceof User ? (string) $user->defaultWarehouseId() : '';
        $this->date = CompanyTime::today()->toDateString();
        $this->requestId = (string) Str::uuid();
        $this->addBlankLine();
    }

    // Customer

    public function chooseCustomer(string $id): void
    {
        if ($id === $this->customerId || ! array_key_exists($id, $this->customerOptions())) {
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
        $this->lines = [];
        $this->addBlankLine();

        // A transfer can't go to the warehouse it leaves from.
        if ($this->toWarehouse === $id) {
            $this->toWarehouse = '';
        }
    }

    // Lines

    /**
     * Any change makes the refusals of the last save out of date.
     */
    public function updated(): void
    {
        $this->resetErrorBag();
    }

    /**
     * Another date offers other expiry dates (stock that came in later was not
     * here yet): a row whose date is no longer offered gets the nearest one.
     */
    public function updatedDate(): void
    {
        if (! $this->ui()->hasLineColumn('expiry_pick')) {
            return;
        }

        foreach ($this->lines as $key => $line) {
            $item = $this->item($line['item']);

            if ($item === null || ! $item['expiry']) {
                continue;
            }

            $options = $this->pickableExpiries($item);

            if (! collect($options)->contains('lot', $line['expiry'])) {
                $this->lines[$key]['expiry'] = $options[0]['lot'] ?? '';
            }
        }
    }

    public function updatedLines(mixed $value, ?string $path = null): void
    {
        // Null when the whole list is replaced at once.
        [$key, $field] = array_pad(explode('.', (string) $path, 2), 2, null);

        // A change makes earlier refusals from saving out of date.
        $this->resetErrorBag();

        if ($field === 'item' && isset($this->lines[$key])) {
            $item = $this->item($this->lines[$key]['item']);
            $this->lines[$key]['serials'] = '';
            $this->lines[$key]['picked'] = [];
            $this->lines[$key]['batch'] = '';
            [$this->lines[$key]['unit'], $this->lines[$key]['expiry']] = $item === null ? ['', ''] : $this->suggestedUnitAndExpiry($key, $item);

            // Counting serial items: suggest that every piece is there; the user unticks what is missing.
            if ($item !== null && $item['serial'] && $this->ui()->countsStock()) {
                $warehouse = $this->chosenWarehouse();
                $this->lines[$key]['picked'] = $warehouse === null ? [] : app(Availability::class)->serials($warehouse->id, $item['id']);
                $this->lines[$key]['qty'] = (string) count($this->lines[$key]['picked']);
            }
        }

        // Serial items: the quantity is the number of serials (on a count, 0 is a count too).
        if (isset($this->lines[$key]) && ($field === 'serials' || str_starts_with((string) $field, 'picked'))) {
            $count = $this->lines[$key]['picked'] !== [] ? count($this->lines[$key]['picked']) : count($this->serialList($this->lines[$key]['serials']));
            $this->lines[$key]['qty'] = $count > 0 || $this->ui()->countsStock() ? (string) $count : '';
        }

        // A batch already known for the item has one expiry date: it is filled in (and locked).
        if ($field === 'batch' && isset($this->lines[$key])) {
            $batch = Batches::normalize($this->lines[$key]['batch'] ?? '');
            $this->lines[$key]['batch'] = $batch;
            $known = $this->lines[$key]['item'] === null ? null : (Batches::dates((string) $this->lines[$key]['item'], [$batch])[$batch] ?? null);

            if ($known !== null) {
                $this->lines[$key]['expiry'] = $known;
            }
        }

        if (in_array($field, ['item', 'unit', 'expiry', 'batch'], true) && isset($this->lines[$key])) {
            $this->rejectDuplicate($key);
        }

        $this->appendBlankIfNeeded();
    }

    /**
     * CLAUDE.md rule 12: a row that repeats another row's item + unit (+ expiry
     * date) is cleared, a toast says where that item already is, and the focus
     * goes to that row's quantity.
     */
    private function rejectDuplicate(string $key): void
    {
        $line = $this->lines[$key];
        $item = $this->item($line['item']);

        if ($item === null || ! $this->hasFullKey($item, $line)) {
            return;
        }

        [$expiry, $batch] = $this->lotOf($line);
        $other = $this->rowWithKey($key, $item, $line['unit'], $expiry, $batch);

        if ($other === null) {
            return;
        }

        [$message, $params] = $this->ui()->duplicateMessage($item['name'], $line['unit'], $expiry, $item['expiry'], $this->rowNumber($other), $batch);
        $this->lines[$key] = ['item' => null, 'unit' => '', 'qty' => '', 'expiry' => '', 'batch' => '', 'serials' => '', 'picked' => []];

        Notify::warning(__($message, $params), on: $this);
        $this->dispatch('focus-field', id: 'line-'.$other.'-qty');
    }

    /**
     * What a row of this item starts on: the base unit (then the larger units)
     * and, for stock going out of an expiry item, the nearest expiry date —
     * the first of these that no other row of the item has already (rule 12),
     * so a second row of an item is another unit or date, not a repeat. When
     * every one is taken, the base unit and nearest date (rejectDuplicate()
     * then clears the row).
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: string}
     */
    private function suggestedUnitAndExpiry(string $key, array $item): array
    {
        $units = $item['serial'] ? [$item['base']] : array_keys($item['units']);
        $expiries = $item['expiry'] && $this->ui()->hasLineColumn('expiry_pick')
            ? array_column($this->pickableExpiries($item), 'lot')
            : [''];
        $expiries = $expiries === [] ? [''] : $expiries;

        foreach ($expiries as $expiry) {
            foreach ($units as $unit) {
                // A receipt's expiry date is typed later: only the unit can be compared yet.
                if ($expiry === '' && $item['expiry']) {
                    return [(string) $units[0], ''];
                }

                if ($this->rowWithKey($key, $item, (string) $unit, ...Batches::split($expiry)) === null) {
                    return [(string) $unit, $expiry];
                }
            }
        }

        return [(string) $units[0], $expiries[0]];
    }

    /**
     * Another row (not $key) with this item, unit and lot (expiry date + batch) as its key, or null.
     *
     * @param  array<string, mixed>  $item
     */
    private function rowWithKey(string $key, array $item, string $unit, ?string $expiry, ?string $batch = null): ?string
    {
        $ui = $this->ui();
        $wanted = $ui->lineKey($item['id'], $unit, $expiry, $item['expiry'], $batch);

        foreach ($this->lines as $otherKey => $other) {
            if ($otherKey === $key || $other['item'] !== $item['id']) {
                continue;
            }

            [$otherExpiry, $otherBatch] = $this->lotOf($other);

            if ($this->hasFullKey($item, $other) && $ui->lineKey($item['id'], $other['unit'], $otherExpiry, $item['expiry'], $otherBatch) === $wanted) {
                return (string) $otherKey;
            }
        }

        return null;
    }

    /**
     * Whether a row's key is complete: a unit, and for an expiry item its lot
     * (picked; or typed: expiry date and batch number).
     *
     * @param  array<string, mixed>  $item
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}  $line
     */
    private function hasFullKey(array $item, array $line): bool
    {
        return $line['unit'] !== '' && (! $item['expiry'] || ($line['expiry'] !== '' && ($this->picksLot() || trim($line['batch'] ?? '') !== '')));
    }

    /**
     * Rows that pick a lot from stock (going out, counting): the expiry field
     * holds the lot (Batches::key()). Receipt rows type the date and the batch.
     */
    private function picksLot(): bool
    {
        $ui = $this->ui();

        return $ui->hasLineColumn('counted') || $ui->hasLineColumn('expiry_pick');
    }

    /**
     * A row's expiry date and batch ('' = stock without a batch; null when none yet).
     *
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}  $line
     * @return array{0: string|null, 1: string|null}
     */
    private function lotOf(array $line): array
    {
        if ($this->picksLot()) {
            return $line['expiry'] === '' ? [null, null] : Batches::split($line['expiry']);
        }

        return [$line['expiry'] === '' ? null : $line['expiry'], Batches::normalize($line['batch'] ?? '')];
    }

    /**
     * A row's number as the grid shows it (1, 2, ...).
     */
    private function rowNumber(string $key): int
    {
        return (int) array_search($key, array_map('strval', array_keys($this->lines)), true) + 1;
    }

    public function deleteLine(string $key): void
    {
        unset($this->lines[$key]);

        if ($this->lines === []) {
            $this->addBlankLine();
        }

        $this->appendBlankIfNeeded();
    }

    public function save(): void
    {
        Gate::authorize($this->ui()->createPermission());

        $check = $this->check();

        if (! $check['valid']) {
            $this->showAll = true;
            $this->notifyInvalid();

            return;
        }

        $ui = $this->ui();

        // Damage / Adjustment posted at once can't be cancelled afterwards, and an
        // Opening Balance is checked before it posts: ask first. Sending for
        // approval changes nothing yet, so it never asks.
        if ($ui->confirmsSave() && ! $check['approval']) {
            $this->askToConfirm(
                'save',
                '',
                __('transactions.form.confirm_final.title', ['action' => $ui->label('save'), 'customer' => $this->customerOptions()[$this->customerId] ?? '']),
                __($ui->cancellable() ? 'transactions.form.confirm_final.body_cancellable' : 'transactions.form.confirm_final.body', ['warehouse' => $this->chosenWarehouse()->name ?? '', 'summary' => $check['summary']]),
                $ui->label('save'),
            );

            return;
        }

        $this->post();
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action !== 'save') {
            return;
        }

        // The modal is only UI: permission and the form are checked again.
        Gate::authorize($this->ui()->createPermission());

        if (! $this->check()['valid']) {
            $this->showAll = true;
            $this->notifyInvalid();

            return;
        }

        $this->post();
    }

    /**
     * Posts the (valid) form and goes to the new document, or shows why not.
     */
    private function post(): void
    {
        $ui = $this->ui();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $transaction = app(PostingService::class)->post($this->document(), $user);
        } catch (DuplicateLine $e) {
            $this->addError('lines.'.$e->otherRow.'.item', __('transactions.form.errors.duplicate_row', ['row' => Format::qty($this->rowNumber((string) $e->row))]));
            $this->addError('lines.'.$e->row.'.item', __('transactions.form.errors.duplicate_row', ['row' => Format::qty($this->rowNumber($e->otherRow))]));
            Notify::error(__($e->key, ['row' => Format::qty($this->rowNumber($e->otherRow))] + $e->params), on: $this);

            return;
        } catch (StockException $e) {
            if ($e->row !== null && isset($this->lines[$e->row])) {
                $this->addError('lines.'.$e->row.'.'.($e->field ?? 'qty'), $e->getMessage());
            } else {
                $this->addError('posting', $e->getMessage());
            }

            // What went wrong, in a toast too: the row may be out of sight.
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $url = $ui->route('show', $transaction->doc_no);
        $params = ['type' => $ui->label('singular'), 'reference' => $transaction->doc_no];

        match (true) {
            $ui->dispatchReceive() => Notify::success(__('feedback.dispatched', $params + ['warehouse' => (string) Warehouse::withoutGlobalScopes()->whereKey($transaction->to_warehouse_id)->value('name')]), $url),
            $transaction->status === 'PENDING_APPROVAL' => Notify::warning(__('feedback.sent_for_approval', $params), $url),
            default => Notify::success(__('feedback.saved', $params), $url),
        };

        $this->redirect($url, navigate: true);
    }

    /**
     * Save was tried on a form that is not valid: the errors are under their
     * fields, and a toast says how many rows need attention.
     */
    private function notifyInvalid(): void
    {
        $check = $this->check();
        $rows = count(array_filter($check['rows'], fn (array $row) => ($row['errors'] ?? []) !== []));

        Notify::error($rows > 0
            ? trans_choice('feedback.rows_need_attention', $rows, ['count' => Format::qty($rows)])
            : ($check['problems'][0] ?? trans_choice('feedback.fields_need_attention', 1, ['count' => Format::qty(1)])), on: $this);
    }

    /**
     * The filled rows as a document for PostingService.
     */
    private function document(): Document
    {
        $lines = [];

        foreach ($this->filledLines() as $key => $line) {
            $item = $this->item($line['item']);
            [$expiry, $batch] = $item !== null && $item['expiry'] ? $this->lotOf($line) : [null, null];

            $lines[$key] = new DocumentLine(
                productId: (string) $line['item'],
                unit: $line['unit'],
                qty: trim($line['qty']),
                expiry: $expiry,
                serials: $line['picked'] !== [] ? $line['picked'] : $this->serialList($line['serials']),
                batch: $batch,
            );
        }

        return new Document(
            type: $this->type,
            warehouseId: (string) $this->chosenWarehouse()?->id,
            customerId: $this->customerId,
            date: CarbonImmutable::parse($this->date),
            note: $this->reference,
            lines: $lines,
            toWarehouseId: $this->ui()->requiresDestination() ? $this->toWarehouse : null,
            reason: $this->ui()->hasHeaderField('reason') ? $this->reason : null,
            requestId: $this->requestId,
        );
    }

    /**
     * Serial numbers typed one per line (or separated by commas), without blanks.
     *
     * @return list<string>
     */
    private function serialList(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text) ?: []), fn (string $serial) => $serial !== ''));
    }

    public function render(): View
    {
        $ui = $this->ui();

        return view('livewire.transactions.form', [
            'ui' => $ui,
            'check' => $this->check(),
            'customers' => $this->customerOptions(),
            'warehouses' => $ui->requiresDestination() ? $this->destinations() : collect(),
            'warehouseOptions' => $this->warehouseOptions(),
            'chosenWarehouse' => $this->chosenWarehouse(),
            'stockDate' => $this->stockDate(),
        ])->title($ui->label('new'));
    }

    /**
     * Everything the view and Save need: each row's numbers, hints and errors,
     * header errors, the footer summary and whether Save is allowed.
     *
     * @return array{rows: array<string, array<string, mixed>>, header: array<string, string>, problems: list<string>, valid: bool, summary: string, filled: int, approval: bool}
     */
    protected function check(): array
    {
        $ui = $this->ui();
        $rows = [];
        $problems = [];
        $used = []; // base quantity already taken per item (and expiry date) by earlier rows
        $baseUnits = [];
        $total = 0.0;
        $approval = false;
        $differences = 0;
        $thresholds = app(ApprovalThresholds::class);
        $selfApproves = $ui->approvePermission() !== null && Gate::allows($ui->approvePermission());
        $lastKey = array_key_last($this->lines);
        $number = 0;
        $filled = 0;
        $this->loadItems();

        foreach ($this->lines as $key => $line) {
            $number++;

            if ($this->isBlank($line)) {
                $rows[$key] = ['blank' => true, 'errors' => []];

                continue;
            }

            $row = $this->checkLine($ui, $line, $used);
            $row['number'] = $number;

            // Rule 12: the same item + unit (+ expiry date) on two rows: both in red.
            if ($row['item'] !== null && $this->hasFullKey($row['item'], $line)
                && ($other = $this->rowWithKey((string) $key, $row['item'], $line['unit'], ...$this->lotOf($line))) !== null) {
                $row['errors']['item'] = __('transactions.form.errors.duplicate_row', ['row' => Format::qty($this->rowNumber($other))]);
            }

            // The row being typed in (the last one) only shows "missing" errors after Save.
            $row['shown'] = $this->showAll || $key !== $lastKey
                ? $row['errors']
                : array_diff_key($row['errors'], array_flip($row['missing']));

            // Refusals from the last save (e.g. stock taken meanwhile) stay until the form changes.
            foreach (['item', 'unit', 'qty', 'expiry', 'batch', 'serials'] as $field) {
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
                // Counts sum the difference with the system; everything else the quantity.
                $total += $ui->hasLineColumn('counted') ? ($row['difference_base'] ?? 0) : ($row['base'] ?? 0);
            }

            // Approvers post their own documents at once (PostingService): no "Send for approval" for them.
            if ($ui->supportsApproval() && ! $selfApproves && $row['item'] !== null && $thresholds->needsApproval(
                (float) ($row['approval_qty'] ?? 0),
                $thresholds->resolve($row['item']['id'], $this->customerId, $ui->code),
            )) {
                $approval = true;
            }

            if (abs((float) ($row['difference_base'] ?? 0)) > 1e-9) {
                $differences++;
            }

            $rows[$key] = $row;
        }

        $header = $this->checkHeader($ui);

        if ($this->customerId === '') {
            array_unshift($problems, __('transactions.form.choose_customer'));
        } elseif ($filled === 0) {
            $problems[] = __('transactions.form.add_a_line');
        } elseif ($ui->countsStock() && $problems === [] && $differences === 0) {
            // Lines that match the system are not saved: with none left there is nothing to save.
            $problems[] = __('stock.errors.nothing_to_adjust');
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
            'summary' => $ui->hasLineColumn('counted')
                ? Format::itemsDifference($filled, $total, $baseUnits)
                : Format::itemsTotal($filled, $total, $baseUnits),
            'filled' => $filled,
            'approval' => $approval,
        ];
    }

    /**
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}  $line
     * @param  array<string, float|string>  $used  also batch => expiry date of earlier rows
     * @return array<string, mixed>
     */
    private function checkLine(TypeUi $ui, array $line, array &$used): array
    {
        $counted = $ui->hasLineColumn('counted');
        $item = $this->item($line['item']);
        $factor = $item['units'][$line['unit']] ?? null;
        $converter = $item ? new UnitConverter($item['units'], $item['base']) : null;
        $errors = [];
        $missing = [];
        $qty = trim($line['qty']);
        $base = null;
        $row = ['blank' => false, 'item' => $item, 'factor' => $factor];

        if ($item === null) {
            $errors['item'] = __('transactions.form.errors.choose_item');
            $missing[] = 'item';
        } elseif ($factor === null) {
            $errors['unit'] = __('transactions.form.errors.choose_unit');
        }

        if ($item !== null && $item['serial']) {
            [$serialErrors, $serialOptions] = $this->checkSerials($ui, $item, $line, $used);
            $row['serial_options'] = $serialOptions;

            if ($serialErrors !== []) {
                $errors['serials'] = $serialErrors[0];
            }

            if (trim($line['qty']) === '' && ! $counted) {
                $errors['serials'] ??= __($ui->postingDirection() === 'IN' ? 'transactions.form.errors.serials_required' : 'transactions.form.errors.serials_pick');
                $missing[] = 'serials';
            }
        }

        // Expiry date: typed on receipts of expiry-tracked items; picked on counts
        // of them (any date here) and on stock going out (dates that may go out).
        $pick = $counted || $ui->hasLineColumn('expiry_pick');
        $needsExpiry = $item !== null && $item['expiry'] && ($ui->hasLineColumn('expiry') || $pick);

        if ($needsExpiry && $pick) {
            $row['expiry_options'] = $counted ? $item['expiries'] : $this->pickableExpiries($item);
            $chosen = collect($row['expiry_options'])->firstWhere('lot', $line['expiry']);

            if ($line['expiry'] === '') {
                $errors['expiry'] = __('transactions.form.errors.expiry_choose');
                $missing[] = 'expiry';
            } elseif ($chosen === null) {
                $errors['expiry'] = __('transactions.form.errors.expiry_not_here');
            }
        }

        if ($needsExpiry && ! $pick) {
            if ($line['expiry'] === '') {
                $errors['expiry'] = __('transactions.form.errors.expiry_required');
                $missing[] = 'expiry';
            } elseif (! strtotime($line['expiry'])) {
                $errors['expiry'] = __('transactions.form.errors.expiry_invalid');
            }

            // Allowed (stock does arrive expired), but never by accident: the row says so.
            $row['expiry_past'] = ! isset($errors['expiry']) && $line['expiry'] < CompanyTime::today()->toDateString();

            // The batch number: required; a known batch keeps its expiry date (also between rows).
            $batch = Batches::normalize($line['batch'] ?? '');
            $known = $batch === '' ? null : ($used['batch|'.$item['id'].'|'.$batch] ?? Batches::dates($item['id'], [$batch])[$batch] ?? null);
            $row['batch_known'] = $known;

            if ($batch === '') {
                $errors['batch'] = __('transactions.form.errors.batch_required');
                $missing[] = 'batch';
            } elseif (mb_strlen($batch) > Batches::MAX) {
                $errors['batch'] = __('settings.errors.too_long', ['max' => Batches::MAX]);
            } elseif (str_contains($batch, '|')) {
                $errors['batch'] = __('transactions.form.errors.batch_invalid');
            } elseif ($known !== null && $line['expiry'] !== '' && $known !== $line['expiry']) {
                $errors['expiry'] = __('stock.errors.batch_other_date', ['batch' => $batch, 'item' => $item['name'], 'date' => Format::date(CarbonImmutable::parse($known))]);
            } elseif ($line['expiry'] !== '' && ! isset($errors['expiry'])) {
                $used['batch|'.$item['id'].'|'.$batch] = $line['expiry'];
            }
        }

        // In stock for this item (or the chosen expiry date when counting or picking one), in base units.
        $perExpiry = $counted || ($needsExpiry && $pick);
        $stockKey = ($item['id'] ?? '').($perExpiry ? '|'.$line['expiry'] : '');

        $inStock = $item === null ? 0.0 : ($perExpiry && $line['expiry'] !== ''
            ? (float) (collect($item['expiries'])->firstWhere('lot', $line['expiry'])['qty'] ?? 0)
            : $item['available']);
        $remaining = max($inStock - (float) ($used[$stockKey] ?? 0), 0);

        if ($qty === '') {
            if (! ($item['serial'] ?? false)) {
                // Serial items get their quantity from the serials (reported above).
                $errors['qty'] = $counted ? __('transactions.form.errors.count_required') : __('transactions.form.errors.qty_required');
                $missing[] = 'qty';
            }
        } elseif (! PostingService::whole($qty)) {
            $errors['qty'] = __('transactions.form.errors.number');
        } elseif ($counted ? (float) $qty < 0 : (float) $qty <= 0) {
            $errors['qty'] = $counted ? __('transactions.form.errors.not_negative') : __('transactions.form.errors.more_than_zero');
        } elseif (PostingService::tooLarge($qty)) {
            $errors['qty'] = __('transactions.form.errors.too_large', ['max' => Format::qty(PostingService::MAX_QTY)]);
        } elseif ($converter !== null && $factor !== null) {
            $base = $converter->toBase($qty, $line['unit']);

            // A service has no stock: never limited by it.
            if ($ui->limitedByStock() && ! $item['service'] && $base > $remaining + 1e-9) {
                $errors['qty'] = $this->stockDate() === null
                    ? __('transactions.form.errors.not_enough', ['qty' => $converter->breakdown($remaining, [$line['unit']])])
                    : __('transactions.form.errors.not_enough_on_date', ['qty' => $converter->breakdown($remaining, [$line['unit']]), 'date' => Format::date($this->stockDate())]);
            }

            $used[$stockKey] = (float) ($used[$stockKey] ?? 0) + $base;
        }

        if ($counted && $converter !== null && $factor !== null) {
            $row['system'] = $converter->fromBase($inStock, $line['unit']);
            $row['difference'] = $base === null ? null : $converter->fromBase($base - $inStock, $line['unit']);
            $row['difference_base'] = $base === null ? null : $base - $inStock;
        }

        return $row + [
            'errors' => $errors,
            'missing' => $missing,
            'base' => $base,
            'remaining' => $remaining,
            // "5 Box + 4 Piece" in the chosen unit and the base unit.
            'available_text' => $converter && $factor && ! $item['service'] ? $converter->breakdown($remaining, [$line['unit']]) : null,
            'conversion' => $base !== null && $factor > 1 ? Format::qtyUnit($base, $item['base']) : null,
            'approval_qty' => $counted ? abs($row['difference_base'] ?? 0) : ($base ?? 0),
        ];
    }

    /**
     * @return array{errors: array<string, string>, missing: array<string, string>}
     */
    private function checkHeader(TypeUi $ui): array
    {
        $errors = [];
        $missing = [];

        if ($this->chosenWarehouse() === null) {
            $errors['warehouse'] = __('transactions.form.errors.warehouse_required');
        }

        if ($this->date === '') {
            $missing['date'] = __('transactions.form.errors.date_required');
        } elseif (! strtotime($this->date)) {
            $errors['date'] = __('transactions.form.errors.date_invalid');
        } elseif (CarbonImmutable::parse($this->date)->toDateString() > CompanyTime::today()->toDateString()) {
            $errors['date'] = __('transactions.form.errors.date_future');
        }

        if ($ui->hasHeaderField('reason')) {
            if ($this->reason === '') {
                $missing['reason'] = __('transactions.form.errors.reason_required');
            } elseif (! in_array($this->reason, $ui->reasons(), true)) {
                $errors['reason'] = __('transactions.form.errors.reason_required');
            }
        }

        if ($ui->hasHeaderField('to_warehouse')) {
            if ($this->toWarehouse === '') {
                $missing['to_warehouse'] = __('transactions.form.errors.to_warehouse_required');
            } elseif (! $this->destinations()->contains('id', $this->toWarehouse)) {
                $errors['to_warehouse'] = __('transactions.form.errors.to_warehouse_invalid');
            }
        }

        if (mb_strlen($this->reference) > 200) {
            $errors['reference'] = __('settings.errors.too_long', ['max' => 200]);
        }

        // Header fields only matter once a customer is chosen.
        return $this->customerId === '' ? ['errors' => [], 'missing' => []] : compact('errors', 'missing');
    }

    /**
     * One of the chosen customer's active items with its stock here (memoized per
     * request). Types that skip expired stock (Delivery note) don't count expired stock.
     *
     * @return array<string, mixed>|null
     */
    private function item(?string $productId): ?array
    {
        if ($productId === null || $this->customerId === '') {
            return null;
        }

        if (! array_key_exists($productId, $this->items)) {
            $this->loadItems([$productId]);
        }

        return $this->items[$productId];
    }

    /**
     * Loads the items of all rows (or of $productIds) not loaded yet in one go,
     * so checking the rows doesn't query per row; unknown ids are kept as null.
     *
     * @param  list<string>|null  $productIds
     */
    private function loadItems(?array $productIds = null): void
    {
        $missing = array_diff(array_unique(array_filter($productIds ?? array_column($this->lines, 'item'))), array_keys($this->items));

        if ($missing === [] || $this->customerId === '') {
            return;
        }

        $ui = $this->ui();
        $found = ItemCatalog::items(
            $this->customerId,
            array_values($missing),
            $this->chosenWarehouse()?->id,
            excludeExpired: $ui->limitedByStock() && ! $ui->usesExpired(),
            date: $this->stockDate(),
            withServices: $ui->allowsServices(),
        );

        foreach ($missing as $id) {
            $this->items[$id] = $found[$id] ?? null;
        }
    }

    /**
     * The document's date when stock goes out (Delivery note, Transfer, Damage) on a
     * day before today: only what was here on that day can go out (the
     * "Available" hint, expiry dates and serials offered). Null when dated today.
     */
    private function stockDate(): ?CarbonImmutable
    {
        $warehouse = $this->chosenWarehouse();

        if (! $this->ui()->limitedByStock() || $warehouse === null || $this->date === '' || ! strtotime($this->date)) {
            return null;
        }

        $date = CarbonImmutable::parse($this->date)->startOfDay();

        return app(Availability::class)->isBackdated($warehouse->id, $date) ? $date : null;
    }

    /**
     * Lots (expiry date + batch) of an expiry item that this type may take stock
     * from here, nearest first: expired ones only when the type uses them (Damage).
     *
     * @param  array<string, mixed>  $item
     * @return list<array{expiry: string, batch: string, lot: string, qty: float, expired: bool}>
     */
    private function pickableExpiries(array $item): array
    {
        $usesExpired = $this->ui()->usesExpired();

        return array_values(array_filter($item['expiries'], fn (array $lot) => $usesExpired || ! $lot['expired']));
    }

    /**
     * Serial numbers of one row. Coming in: typed, each new to stock and not on
     * another row. Going out: picked from those in stock here.
     *
     * @param  array<string, mixed>  $item
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}  $line
     * @param  array<string, float|string>  $used  also remembers serials seen on earlier rows
     * @return array{0: list<string>, 1: list<string>} errors, and the serials that can be picked
     */
    private function checkSerials(TypeUi $ui, array $item, array $line, array &$used): array
    {
        $errors = [];

        if ($ui->postingDirection() === 'IN') {
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

        $warehouse = $this->chosenWarehouse();
        $options = $warehouse === null ? [] : app(Availability::class)->serials($warehouse->id, $item['id'], $this->stockDate());

        foreach ($line['picked'] as $serial) {
            if (! in_array($serial, $options, true)) {
                $errors[] = $this->stockDate() !== null && in_array($serial, app(Availability::class)->serials($warehouse->id, $item['id']), true)
                    ? __('stock.errors.serial_not_on_date', ['serial' => $serial, 'date' => Format::date($this->stockDate())])
                    : __('stock.errors.serial_not_in_stock', ['serial' => $serial]);
            }
        }

        return [$errors, $options];
    }

    /**
     * Active customers, id => name.
     *
     * @return array<string, string>
     */
    private function customerOptions(): array
    {
        return $this->customers ??= Customer::where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Where a transfer can go: every active warehouse except this one.
     *
     * @return Collection<int, Warehouse>
     */
    private function destinations(): Collection
    {
        return Warehouse::where('active', true)
            ->when($this->chosenWarehouse(), fn ($query, Warehouse $current) => $query->whereKeyNot($current->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * The chosen warehouse, while it is one of the user's (memoized per request).
     */
    private function chosenWarehouse(): ?Warehouse
    {
        if (! array_key_exists($this->warehouseId, $this->warehouseOptions())) {
            return null;
        }

        return $this->warehouse ??= Warehouse::find($this->warehouseId);
    }

    /**
     * The user's active warehouses (user_warehouse), id => name.
     *
     * @return array<string, string>
     */
    private function warehouseOptions(): array
    {
        $user = Auth::user();

        return $this->warehouses ??= $user instanceof User ? $user->assignedWarehouses()->pluck('name', 'id')->all() : [];
    }

    private function ui(): TypeUi
    {
        return TransactionTypes::get($this->type);
    }

    private function applyCustomer(string $id): void
    {
        $this->customerId = $id;
        $this->pendingCustomer = null;
        $this->customerVersion++;
        $this->showAll = false;
        $this->lines = [];
        $this->addBlankLine();
    }

    /**
     * @return array<string, array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}>
     */
    private function filledLines(): array
    {
        return array_filter($this->lines, fn (array $line) => ! $this->isBlank($line));
    }

    /**
     * @param  array{item: ?string, unit: string, qty: string, expiry: string, batch?: string, serials: string, picked: list<string>}  $line
     */
    private function isBlank(array $line): bool
    {
        return $line['item'] === null && trim($line['qty']) === '' && $line['expiry'] === '' && trim($line['batch'] ?? '') === ''
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
        $this->lines['r'.$this->nextLine++] = ['item' => null, 'unit' => '', 'qty' => '', 'expiry' => '', 'batch' => '', 'serials' => '', 'picked' => []];
    }
}
