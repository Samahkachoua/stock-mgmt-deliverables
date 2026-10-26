<?php

namespace App\Livewire\Transactions;

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Availability;
use App\Services\Stock\DeliveredLine;
use App\Services\Stock\DocumentLine;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\ItemCatalog;
use App\Services\Stock\PostingService;
use App\Services\Stock\UnitConverter;
use App\Support\Batches;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Transactions\Documents;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Confirming what reached the customer, on a Delivery note that is Confirmed
 * or Dispatched (stock.out.create, in its warehouse): the Delivered column
 * starts on what was ordered and can be lowered, never raised; less needs a
 * reason. With "Another item delivered instead", the user picks that item,
 * its unit, lot and quantity. "Confirm delivery" posts through
 * PostingService::deliver(): only then does stock leave.
 */
class DeliverNote extends Component
{
    #[Locked]
    public string $transactionId = '';

    /** @var array<string, string> line id => delivered, in the line's unit */
    public array $delivered = [];

    /** @var array<string, string> line id => why less was delivered */
    public array $reasons = [];

    /** @var array<string, array<int, string>> line id => serial numbers delivered */
    public array $serials = [];

    /**
     * Line id => the item delivered instead (reason REPLACED).
     *
     * @var array<string, array{item: ?string, unit: string, qty: string, expiry: string, picked: array<int, string>}>
     */
    public array $instead = [];

    /** @var array<string, array<string, mixed>|null> per-request cache: product => catalog item */
    private array $items = [];

    public function mount(): void
    {
        foreach ($this->document()['lines'] as $line) {
            // Starts on what was ordered: the usual case is that everything arrived.
            $this->delivered[$line['id']] = (string) $line['qty'];
            $this->reasons[$line['id']] = '';
            $this->serials[$line['id']] = $line['serials'];
            $this->instead[$line['id']] = ['item' => null, 'unit' => '', 'qty' => '', 'expiry' => '', 'picked' => []];
        }
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag();
        $parts = explode('.', $property);

        // Serial items: the delivered quantity is the number of serials ticked.
        if ($parts[0] === 'serials') {
            $this->delivered[$parts[1]] = (string) count($this->serials[$parts[1]] ?? []);
        }

        // Another item chosen: its base unit and nearest lot, empty quantity.
        if ($parts[0] === 'instead' && ($parts[2] ?? '') === 'item') {
            $item = $this->item($this->instead[$parts[1]]['item']);
            $this->instead[$parts[1]] = [
                'item' => $this->instead[$parts[1]]['item'],
                'unit' => $item === null ? '' : (string) array_key_first($item['units']),
                'qty' => '',
                'expiry' => $item === null ? '' : ($this->lots($item)[0]['lot'] ?? ''),
                'picked' => [],
            ];
        }

        if ($parts[0] === 'instead' && ($parts[2] ?? '') === 'picked') {
            $this->instead[$parts[1]]['qty'] = (string) count($this->instead[$parts[1]]['picked']);
        }
    }

    public function confirm(): void
    {
        $document = $this->document();

        if (! $this->check($document)['valid']) {
            Notify::error(__('feedback.rows_need_attention', ['count' => count(array_filter($this->check($document)['rows'], fn ($row) => $row['error'] || $row['reason_error'] || $row['instead_error']))]), on: $this);

            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $lines = [];
        foreach ($document['lines'] as $line) {
            $id = $line['id'];
            $reason = ($this->reasons[$id] ?? '') ?: null;
            $instead = null;

            if ($reason === 'REPLACED' && filled($this->instead[$id]['item'] ?? null)) {
                [$expiry, $batch] = $this->instead[$id]['expiry'] === '' ? [null, null] : Batches::split($this->instead[$id]['expiry']);
                $instead = new DocumentLine((string) $this->instead[$id]['item'], $this->instead[$id]['unit'], trim($this->instead[$id]['qty']), $expiry, array_values($this->instead[$id]['picked']), $batch);
            }

            $lines[$id] = new DeliveredLine(
                qty: trim($this->delivered[$id] ?? ''),
                reason: (float) ($this->delivered[$id] ?? 0) < $line['qty'] ? $reason : null,
                serials: array_values($this->serials[$id] ?? []),
                instead: $instead,
            );
        }

        try {
            app(PostingService::class)->deliver(StockTransaction::query()->findOrFail($this->transactionId), $lines, $user);
        } catch (StockException $e) {
            $field = in_array($e->field, ['item', 'unit', 'qty', 'expiry', 'batch'], true) ? 'instead' : ($e->field === 'reason' ? 'reason' : 'received');
            $this->addError($e->row !== null ? 'lines.'.$e->row.'.'.$field : 'delivery', $e->getMessage());
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $short = collect($document['lines'])->contains(fn ($line) => (float) ($this->delivered[$line['id']] ?? 0) < $line['qty']);
        $url = TransactionTypes::get('OUT')->route('show', $document['reference']);

        $short
            ? Notify::warning(__('feedback.delivered_short', ['reference' => $document['reference']]), $url)
            : Notify::success(__('feedback.delivered', ['reference' => $document['reference']]), $url);

        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $document = $this->document();

        return view('livewire.transactions.deliver-note', [
            'document' => $document,
            'check' => $this->check($document),
        ]);
    }

    /**
     * Per line: the error to show (if any), whether a reason is needed, the
     * shortage, and the item delivered instead with its own check.
     *
     * @param  array<string, mixed>  $document
     * @return array{rows: array<string, array<string, mixed>>, valid: bool}
     */
    private function check(array $document): array
    {
        $rows = [];
        $valid = true;

        foreach ($document['lines'] as $line) {
            $id = $line['id'];
            $text = trim($this->delivered[$id] ?? '');
            $error = $this->getErrorBag()->first('lines.'.$id.'.received') ?: null;
            $reasonError = $this->getErrorBag()->first('lines.'.$id.'.reason') ?: null;
            $insteadError = $this->getErrorBag()->first('lines.'.$id.'.instead') ?: null;
            $short = null;
            $replacement = null;

            if ($text === '' || (float) $text < 0) {
                $error ??= __('deliveries.errors.delivered_required');
            } elseif (! PostingService::whole($text)) {
                $error ??= __('transactions.form.errors.number');
            } elseif ((float) $text > $line['qty'] + 1e-9) {
                $error ??= __('deliveries.errors.more_than_ordered', ['qty' => Format::qtyUnit($line['qty'], $line['unit'])]);
            } elseif ((float) $text < $line['qty']) {
                $short = __('deliveries.deliver.difference', ['qty' => Format::qtyUnit($line['qty'] - (float) $text, $line['unit'])]);

                if (($this->reasons[$id] ?? '') === '') {
                    $reasonError ??= __('deliveries.errors.reason_required');
                } elseif ($this->reasons[$id] === 'REPLACED') {
                    [$replacement, $problem] = $this->checkInstead($this->instead[$id] ?? []);
                    $insteadError ??= $problem;
                }
            }

            $valid = $valid && $error === null && $reasonError === null && $insteadError === null;
            $rows[$id] = ['error' => $error, 'reason_error' => $reasonError, 'instead_error' => $insteadError, 'short' => $short, 'replacement' => $replacement];
        }

        return ['rows' => $rows, 'valid' => $valid && ! $this->getErrorBag()->has('delivery')];
    }

    /**
     * The item delivered instead: chosen, in one of its units, a lot that may
     * go out (expiry items), a whole quantity within what is available (not
     * reserved). Returns what the row shows (item, lots, serials, hint) and the
     * first problem.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function checkInstead(array $entry): array
    {
        $item = $this->item($entry['item'] ?? null);

        if ($item === null) {
            return [['item' => null], __('deliveries.errors.instead_required')];
        }

        $converter = new UnitConverter($item['units'], $item['base']);
        $lots = $item['expiry'] ? $this->lots($item) : [];
        $lot = collect($lots)->firstWhere('lot', $entry['expiry'] ?? '');
        $available = $item['expiry'] ? (float) ($lot['qty'] ?? 0) : $item['available'];
        $serials = $item['serial'] ? app(Availability::class)->serials($this->warehouseId(), $item['id']) : [];
        $view = ['item' => $item, 'lots' => $lots, 'serials' => $serials, 'available' => isset($item['units'][$entry['unit'] ?? '']) && ! $item['service'] ? $converter->breakdown($available, [$entry['unit']]) : null];
        $qty = trim((string) ($entry['qty'] ?? ''));

        $problem = match (true) {
            ! isset($item['units'][$entry['unit'] ?? '']) => __('transactions.form.errors.choose_unit'),
            $item['expiry'] && $lot === null => __('transactions.form.errors.expiry_choose'),
            $qty === '' => __('transactions.form.errors.qty_required'),
            ! PostingService::whole($qty) => __('transactions.form.errors.number'),
            (float) $qty <= 0 => __('transactions.form.errors.more_than_zero'),
            ! $item['service'] && $converter->toBase($qty, $entry['unit']) > $available + 1e-9 => __('transactions.form.errors.not_enough', ['qty' => $converter->breakdown($available, [$entry['unit']])]),
            default => null,
        };

        return [$view, $problem];
    }

    /**
     * Lots of an expiry item that may go out, nearest first (not expired, not all reserved).
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function lots(array $item): array
    {
        return array_values(array_filter($item['expiries'], fn (array $lot) => ! $lot['expired'] && $lot['qty'] > 0));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function item(?string $productId): ?array
    {
        if ($productId === null || $productId === '') {
            return null;
        }

        if (! array_key_exists($productId, $this->items)) {
            $transaction = StockTransaction::query()->findOrFail($this->transactionId);
            $this->items[$productId] = ItemCatalog::item($transaction->customer_id, $productId, $transaction->warehouse_id, excludeExpired: true, withServices: true);
        }

        return $this->items[$productId];
    }

    private function warehouseId(): string
    {
        return (string) StockTransaction::query()->findOrFail($this->transactionId)->warehouse_id;
    }

    /**
     * The delivery note, if this user may deliver it now; 403 otherwise.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $transaction = StockTransaction::query()->findOrFail($this->transactionId);
        $type = TransactionTypes::get($transaction->transaction_type);
        Gate::authorize($type->createPermission());

        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWarehouse($transaction->warehouse_id), 403);

        $document = Documents::find($type, $transaction->doc_no);
        abort_if($document === null, 404);

        return $document;
    }
}
