<?php

namespace App\Livewire\Transactions;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Approve / Reject on the details page of a Damage or Adjustment waiting for
 * approval (StockTransactionPolicy::decide: the type's approve permission, not
 * one's own document). Approve posts at once, without asking; Reject asks in
 * the confirmation modal, with a required reason (CLAUDE.md "Confirmations").
 * Refusals (e.g. stock changed since the count) are shown under the buttons.
 */
class DecideApproval extends Component
{
    use ConfirmsActions;

    #[Locked]
    public string $transactionId = '';

    public ?string $problem = null;

    public function startReject(): void
    {
        $transaction = $this->authorizeDecision();
        $this->problem = null;

        $name = mb_strtolower(TransactionTypes::get($transaction->transaction_type)->label('singular'));

        $this->askToConfirm(
            'reject', $transaction->id,
            __('confirm.reject.title', ['type' => $name, 'reference' => $transaction->doc_no]),
            __('confirm.reject.body', ['name' => (string) DB::table('app_user')->where('id', $transaction->created_by)->value('full_name')]),
            __('confirm.reject.button', ['type' => $name]),
            __('confirm.reject.reason'),
            __('stock.approval.reject_placeholder'),
        );
    }

    public function approve(): void
    {
        $transaction = $this->authorizeDecision();

        try {
            app(PostingService::class)->approve($transaction, $this->user());
        } catch (StockException $e) {
            $this->refuse($e);

            return;
        }

        $this->finish($transaction, 'feedback.approved');
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        if ($action !== 'reject') {
            return;
        }

        // The modal is only UI: the policy and PostingService check again.
        $transaction = $this->authorizeDecision();

        try {
            app(PostingService::class)->reject($transaction, $reason, $this->user());
        } catch (StockException $e) {
            $this->refuse($e);

            return;
        }

        $this->finish($transaction, 'feedback.rejected');
    }

    public function render(): View
    {
        $transaction = StockTransaction::query()->findOrFail($this->transactionId);

        return view('livewire.transactions.decide-approval', [
            'decision' => Gate::inspect('decide', $transaction),
        ]);
    }

    private function refuse(StockException $e): void
    {
        $this->problem = $e->getMessage();
        Notify::error($e->getMessage(), on: $this);
    }

    private function authorizeDecision(): StockTransaction
    {
        $transaction = StockTransaction::query()->findOrFail($this->transactionId);
        Gate::authorize('decide', $transaction);

        return $transaction;
    }

    private function finish(StockTransaction $transaction, string $message): void
    {
        $type = TransactionTypes::get($transaction->transaction_type);
        $url = $type->route('show', $transaction->doc_no);

        Notify::success(__($message, ['type' => $type->label('singular'), 'reference' => $transaction->doc_no]), $url);
        $this->redirect($url, navigate: true);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
