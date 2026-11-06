<?php

namespace App\Livewire\Transactions;

use App\Models\Driver;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Support\Ids;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Dispatch" on a Confirmed Delivery note (stock.out.create, in its
 * warehouse): a small dialog where the user chooses one of the drivers
 * (Settings › Drivers; required) and sees their details, then
 * PostingService::dispatchDelivery(). Not a destructive action: nothing
 * changes in stock, so no confirmation question.
 */
class DispatchDelivery extends Component
{
    #[Locked]
    public string $transactionId = '';

    public bool $open = false;

    public string $driverId = '';

    public function show(): void
    {
        $this->transaction();
        $this->resetErrorBag();
        $this->open = true;
    }

    public function updatedDriverId(): void
    {
        $this->resetErrorBag('driverId');
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function dispatchNote(): void
    {
        $transaction = $this->transaction();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(PostingService::class)->dispatchDelivery($transaction, $this->driverId, $user);
        } catch (StockException $e) {
            $this->addError($e->field === 'driver_id' ? 'driverId' : 'dispatch', $e->getMessage());
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $url = TransactionTypes::get($transaction->transaction_type)->route('show', $transaction->doc_no);
        Notify::success(__('feedback.delivery_dispatched', ['reference' => $transaction->doc_no]), $url);
        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        $drivers = $this->open ? Driver::query()->orderBy('name')->orderBy('id')->get() : collect();

        return view('livewire.transactions.dispatch-delivery', [
            'reference' => $this->transaction()->doc_no,
            'drivers' => $drivers,
            'driver' => Ids::valid($this->driverId) ? $drivers->firstWhere('id', $this->driverId) : null,
            'canAddDriver' => Gate::allows('create', Driver::class),
        ]);
    }

    /**
     * The delivery note, if this user may dispatch it (the type's permission,
     * its warehouse; the warehouse scope on StockTransaction applies).
     */
    private function transaction(): StockTransaction
    {
        $transaction = StockTransaction::query()->findOrFail($this->transactionId);
        Gate::authorize(TransactionTypes::get($transaction->transaction_type)->createPermission());

        return $transaction;
    }
}
