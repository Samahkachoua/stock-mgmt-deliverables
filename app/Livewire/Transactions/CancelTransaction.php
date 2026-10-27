<?php

namespace App\Livewire\Transactions;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Transactions\Documents;
use App\Support\Transactions\TransactionTypes;
use App\Support\Transactions\TypeUi;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Cancel document" on a posted document's details page (TypeUi::cancelPermission():
 * the type's own cancel permission, or for a transfer In transit its sender's dispatch permission).
 * Asks first, in the confirmation modal, with what happens to the stock and a
 * required reason (CLAUDE.md "Confirmations"); then reverses the stock through
 * PostingService::cancel(). A document that can't be cancelled (its stock has
 * already been used, it was received meanwhile) says why instead of asking.
 */
class CancelTransaction extends Component
{
    use ConfirmsActions;

    #[Locked]
    public string $transactionId = '';

    public ?string $problem = null;

    public function ask(): void
    {
        $transaction = $this->authorizeCancel();
        $this->problem = null;

        if ($refusal = app(PostingService::class)->cancelRefusal($transaction, $this->user())) {
            $this->refuse($refusal);

            return;
        }

        $type = TransactionTypes::get($transaction->transaction_type);
        $name = mb_strtolower($type->label('singular'));

        $this->askToConfirm(
            'cancel', $transaction->id,
            __('confirm.cancel.title', ['type' => $name, 'reference' => $transaction->doc_no]),
            $this->consequence($type, $transaction),
            __('confirm.cancel.button', ['type' => $name]),
            __('confirm.cancel.reason'),
            __('confirm.cancel.reason_placeholder'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        // The modal is only UI: permission and rules are checked again here and in PostingService.
        $transaction = $this->authorizeCancel();
        $undelivered = in_array($transaction->status, ['CONFIRMED', 'DISPATCHED'], true) && TransactionTypes::get($transaction->transaction_type)->deliverySteps();

        try {
            app(PostingService::class)->cancel($transaction, $this->user(), $reason);
        } catch (StockException $e) {
            $this->refuse($e);

            return;
        }

        $type = TransactionTypes::get($transaction->transaction_type);
        $url = $type->route('show', $transaction->doc_no);

        Notify::success($undelivered
            ? __('feedback.delivery_cancelled', ['reference' => $transaction->doc_no])
            : __('feedback.cancelled', ['type' => $type->label('singular'), 'reference' => $transaction->doc_no]), $url);
        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.transactions.cancel-transaction');
    }

    /**
     * "This returns 36 Piece of Canned Beans 400g to Beirut Main stock and can't be undone."
     * A receipt's stock is taken out again; anything else goes back to where it left from.
     */
    private function consequence(TypeUi $type, StockTransaction $transaction): string
    {
        $document = Documents::find($type, $transaction->doc_no);
        $lines = $document['lines'] ?? [];
        $stock = $lines === [] ? '' : __('confirm.cancel.stock', [
            'qty' => Format::qtyUnit($lines[0]['qty'], $lines[0]['unit']),
            'item' => $lines[0]['name'],
        ]);

        if (count($lines) > 1) {
            $others = count($lines) - 1;
            $stock = trans_choice('confirm.cancel.more', $others, ['first' => $stock, 'count' => Format::qty($others)]);
        }

        // A delivery note not delivered yet only gives back what it reserved.
        $undelivered = $type->deliverySteps() && in_array($transaction->status, ['CONFIRMED', 'DISPATCHED'], true);

        return __($undelivered ? 'confirm.cancel.releases' : ($type->postingDirection() === 'IN' ? 'confirm.cancel.removes' : 'confirm.cancel.returns'), [
            'stock' => $stock,
            'warehouse' => $document['warehouse'] ?? '',
        ]);
    }

    private function refuse(StockException $e): void
    {
        $this->problem = $e->getMessage();
        Notify::error($e->getMessage(), on: $this);
    }

    /**
     * Permission, and the document must be one the user can see (warehouse and
     * customer scopes on StockTransaction).
     */
    private function authorizeCancel(): StockTransaction
    {
        $transaction = StockTransaction::query()->findOrFail($this->transactionId);
        $type = TransactionTypes::get($transaction->transaction_type);
        abort_unless($type->cancellable(), 403);
        Gate::authorize($type->cancelPermission());

        return $transaction;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
