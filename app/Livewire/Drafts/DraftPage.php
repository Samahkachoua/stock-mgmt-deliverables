<?php

namespace App\Livewire\Drafts;

use App\Livewire\Concerns\ConfirmsActions;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Stock\DraftService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Drafts\DraftDocuments;
use App\Support\Drafts\DraftTypes;
use App\Support\Drafts\DraftUi;
use App\Support\Format;
use App\Support\Notify;
use App\Support\Transactions\TransactionTypes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The read-only details page of a draft receipt / issue: summary, lines by
 * warehouse, the documents confirming created, activity. While it is a draft:
 * Edit, Confirm (no question asked, like approving: it posts at once and the
 * documents can be cancelled one by one) and Cancel draft (asks, with a reason).
 *
 * A confirmation that fails shows why in a toast and under the line concerned.
 */
#[Layout('components.layouts.app')]
class DraftPage extends Component
{
    use ConfirmsActions;

    /** transaction_type.code the draft creates, from the route. */
    #[Locked]
    public string $type = '';

    #[Locked]
    public string $reference = '';

    /**
     * stock_draft.updated_at as shown: a draft someone changes meanwhile is not
     * confirmed without being seen.
     */
    #[Locked]
    public string $version = '';

    /** @var array<string, string> draft line id => why it can't be confirmed */
    public array $lineErrors = [];

    /** @var array<string, mixed>|null the draft, read once per request */
    private ?array $loaded = null;

    public function mount(string $type, string $reference): void
    {
        $this->type = DraftTypes::get($type)->code;
        $this->reference = $reference;
        $this->version = (string) ($this->draft()['version'] ?? '');
    }

    public function confirmDraft(): void
    {
        $draft = $this->draft();
        $this->authorize('confirm', $draft['model']);
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $this->lineErrors = [];

        try {
            $documents = app(DraftService::class)->confirm($draft['model'], $user, version: $this->version);
        } catch (StockException $e) {
            if ($e->row !== null) {
                $this->lineErrors[$e->row] = $e->getMessage();
            }

            Notify::error($e->getMessage(), on: $this);

            return;
        }

        Notify::success($this->confirmedMessage($this->kind(), $draft['reference'], $documents), $this->kind()->route('show', $draft['reference']));

        $this->redirect($this->kind()->route('show', $draft['reference']), navigate: true);
    }

    public function askCancel(): void
    {
        $draft = $this->draft();
        $this->authorize('cancel', $draft['model']);

        $this->askToConfirm(
            'cancel',
            $draft['id'],
            __('drafts.confirm_cancel.title', ['type' => $this->kind()->label('singular'), 'reference' => $draft['reference']]),
            __('drafts.confirm_cancel.body'),
            __('drafts.confirm_cancel.button', ['type' => $this->kind()->label('singular')]),
            __('drafts.confirm_cancel.reason'),
            __('drafts.confirm_cancel.reason_placeholder'),
        );
    }

    protected function confirmed(string $action, string $id, string $reason): void
    {
        $draft = $this->draft();

        if ($action !== 'cancel' || $draft['id'] !== $id) {
            return;
        }

        // The modal is only UI: permission and rules are checked again.
        $this->authorize('cancel', $draft['model']);
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            app(DraftService::class)->cancel($draft['model'], $user, $reason);
        } catch (StockException $e) {
            Notify::error($e->getMessage(), on: $this);

            return;
        }

        Notify::success(__('drafts.feedback.cancelled', ['type' => $this->kind()->label('singular'), 'reference' => $draft['reference']]), $this->kind()->route('show', $draft['reference']));
        $this->redirect($this->kind()->route('show', $draft['reference']), navigate: true);
    }

    public function render(): View
    {
        $draft = $this->draft();
        $kind = $this->kind();
        $confirm = Gate::inspect('confirm', $draft['model']);

        return view('livewire.drafts.show', [
            'kind' => $kind,
            'draft' => $draft,
            'summary' => DraftDocuments::summary($kind, $draft),
            'canEdit' => Gate::allows('update', $draft['model']),
            'canConfirm' => $confirm->allowed(),
            // A confirmer who lacks a warehouse of the draft is told which.
            'confirmBlocked' => $draft['status'] === 'DRAFT' && Gate::allows($kind->confirmPermission()) && $confirm->denied() ? $confirm->message() : null,
            'canCancel' => Gate::allows('cancel', $draft['model']),
        ])->title($draft['reference']);
    }

    /**
     * "Draft receipt DRR-2026-000004 confirmed — 2 receipts created".
     *
     * @param  list<StockTransaction>  $documents
     */
    public static function confirmedMessage(DraftUi $kind, string $reference, array $documents): string
    {
        $count = count($documents);

        return __('drafts.feedback.confirmed', [
            'type' => $kind->label('singular'),
            'reference' => $reference,
            'documents' => trans_choice('drafts.feedback.documents_'.strtolower($kind->code), $count, ['count' => Format::qty($count)]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        return $this->loaded ??= DraftDocuments::find($this->kind(), $this->reference) ?? abort(404);
    }

    private function kind(): DraftUi
    {
        return DraftTypes::get($this->type);
    }

    /**
     * The details page route of a document created from this draft.
     */
    public static function documentUrl(string $type, string $reference): string
    {
        return TransactionTypes::get($type)->route('show', $reference);
    }
}
