<?php

namespace App\Livewire\Transactions;

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Services\Stock\ReceivedLine;
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
 * Receiving a transfer at its destination (stock.transfer.receive, user of the
 * destination warehouse, transfer In transit): the Received column is filled in
 * with what was sent and can be lowered, never raised; a lower quantity needs a
 * reason. "Confirm receipt" posts through PostingService::receive().
 */
class ReceiveTransfer extends Component
{
    #[Locked]
    public string $transactionId = '';

    /** @var array<string, string> line id => received, in the line's unit */
    public array $received = [];

    /** @var array<string, string> line id => shortage reason */
    public array $reasons = [];

    /** @var array<string, array<int, string>> line id => serial numbers that arrived (keys may have gaps after unticking) */
    public array $serials = [];

    public function mount(): void
    {
        $document = $this->document();

        foreach ($document['lines'] as $line) {
            // Pre-filled with what was sent: the usual case is that everything arrived.
            $this->received[$line['id']] = (string) $line['qty'];
            $this->reasons[$line['id']] = '';
            $this->serials[$line['id']] = $line['serials'];
        }
    }

    public function updated(string $property): void
    {
        $this->resetErrorBag();

        // Serial items: the received quantity is the number of serials ticked.
        if (str_starts_with($property, 'serials.')) {
            $lineId = explode('.', $property)[1];
            $this->received[$lineId] = (string) count($this->serials[$lineId] ?? []);
        }
    }

    public function confirm(): void
    {
        $document = $this->document();

        if (! $this->check($document)['valid']) {
            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $lines = [];
        foreach ($document['lines'] as $line) {
            $lines[$line['id']] = new ReceivedLine(
                qty: trim($this->received[$line['id']] ?? ''),
                reason: ($this->reasons[$line['id']] ?? '') ?: null,
                serials: array_values($this->serials[$line['id']] ?? []),
            );
        }

        try {
            app(PostingService::class)->receive(StockTransaction::query()->findOrFail($this->transactionId), $lines, $user);
        } catch (StockException $e) {
            $this->addError($e->row !== null ? 'lines.'.$e->row.'.'.($e->field === 'reason' ? 'reason' : 'received') : 'receipt', $e->getMessage());
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $short = collect($document['lines'])->contains(fn ($line) => (float) ($this->received[$line['id']] ?? 0) < $line['qty']);
        $url = TransactionTypes::get('TRANSFER')->route('show', $document['reference']);

        $short
            ? Notify::warning(__('feedback.received_with_shortage', ['reference' => $document['reference']]), $url)
            : Notify::success(__('feedback.received', ['reference' => $document['reference']]), $url);

        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $document = $this->document();

        return view('livewire.transactions.receive-transfer', [
            'document' => $document,
            'check' => $this->check($document),
        ]);
    }

    /**
     * Per line: the error to show (if any), whether a reason is needed, and the shortage.
     *
     * @param  array<string, mixed>  $document
     * @return array{rows: array<string, array{error: string|null, reason_error: string|null, short: string|null}>, valid: bool}
     */
    private function check(array $document): array
    {
        $rows = [];
        $valid = true;

        foreach ($document['lines'] as $line) {
            $id = $line['id'];
            $text = trim($this->received[$id] ?? '');
            $error = $this->getErrorBag()->first('lines.'.$id.'.received') ?: null;
            $reasonError = $this->getErrorBag()->first('lines.'.$id.'.reason') ?: null;
            $short = null;

            if ($text === '' || (float) $text < 0) {
                $error ??= __('transfers.errors.received_required');
            } elseif (! PostingService::whole($text)) {
                $error ??= __('transactions.form.errors.number');
            } elseif ((float) $text > $line['qty'] + 1e-9) {
                $error ??= __('transfers.errors.more_than_sent', ['qty' => Format::qtyUnit($line['qty'], $line['unit'])]);
            } elseif ((float) $text < $line['qty']) {
                $short = __('transfers.receive.difference', ['qty' => Format::qtyUnit($line['qty'] - (float) $text, $line['unit'])]);

                if (($this->reasons[$id] ?? '') === '') {
                    $reasonError ??= __('transfers.errors.reason_required');
                }
            }

            $valid = $valid && $error === null && $reasonError === null;
            $rows[$id] = ['error' => $error, 'reason_error' => $reasonError, 'short' => $short];
        }

        return ['rows' => $rows, 'valid' => $valid && ! $this->getErrorBag()->has('receipt')];
    }

    /**
     * The transfer, if this user may receive it now; 403 otherwise.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        Gate::authorize('stock.transfer.receive');

        $transaction = StockTransaction::query()->findOrFail($this->transactionId);
        $user = Auth::user();

        abort_unless($user instanceof User && $user->canAccessWarehouse($transaction->to_warehouse_id), 403);

        $document = Documents::find(TransactionTypes::get($transaction->transaction_type), $transaction->doc_no);
        abort_if($document === null, 404);

        return $document;
    }
}
