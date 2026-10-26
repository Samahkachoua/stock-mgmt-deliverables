<?php

namespace App\Livewire\Transactions;

use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\PostingService;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Dispatch" on a Confirmed Delivery note (stock.out.create, in its
 * warehouse): a small dialog with the driver's name, phone and the truck
 * number, all optional, then PostingService::dispatchDelivery(). Not a
 * destructive action: nothing changes in stock, so no confirmation question.
 */
class DispatchDelivery extends Component
{
    #[Locked]
    public string $transactionId = '';

    public bool $open = false;

    public string $driverName = '';

    public string $driverPhone = '';

    public string $truckNo = '';

    public function show(): void
    {
        $this->transaction();
        $this->resetErrorBag();
        $this->open = true;
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
            app(PostingService::class)->dispatchDelivery($transaction, $this->driverName, $this->driverPhone, $this->truckNo, $user);
        } catch (StockException $e) {
            $fields = ['driver_name' => 'driverName', 'driver_phone' => 'driverPhone', 'truck_no' => 'truckNo'];
            $this->addError($fields[$e->field] ?? 'dispatch', $e->getMessage());
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        $url = TransactionTypes::get($transaction->transaction_type)->route('show', $transaction->doc_no);
        Notify::success(__('feedback.delivery_dispatched', ['reference' => $transaction->doc_no]), $url);
        $this->redirect($url, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.transactions.dispatch-delivery', [
            'reference' => $this->transaction()->doc_no,
            'max' => PostingService::MAX_DRIVER,
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
