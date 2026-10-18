<?php

namespace App\Livewire\Concerns;

use App\Services\Stock\PostingService;
use Livewire\Attributes\Locked;

/**
 * Destructive actions ask first, in the shared modal (CLAUDE.md
 * "Confirmations"; view: x-confirm-modal). Deleting, deactivating, cancelling
 * and rejecting use it, and so does saving a Damage / Adjustment that posts at
 * once (it can't be cancelled); other saves, approving and confirming a
 * receipt never do.
 *
 * A component opens the modal with askToConfirm() — after checking that the
 * action is possible at all (a record that can't be deleted shows why
 * instead) — and does the work in confirmed(), which re-checks permission and
 * rules: the modal is only UI. What is being confirmed is locked, so the
 * browser can't swap in another action or record.
 */
trait ConfirmsActions
{
    /** Longest reason, in characters (the same as what PostingService accepts). */
    public const MAX_CONFIRM_REASON = PostingService::MAX_REASON;

    /**
     * The open confirmation; null = none. `reason` is the label of the
     * required reason field, or null when no reason is asked.
     *
     * @var array{action: string, id: string, title: string, body: string, button: string, reason: string|null, placeholder: string|null}|null
     */
    #[Locked]
    public ?array $confirm = null;

    public string $confirmReason = '';

    /**
     * Does what was confirmed. Must check permission and rules again.
     */
    abstract protected function confirmed(string $action, string $id, string $reason): void;

    /**
     * @param  string  $title  names the action and the record: "Delete unit Box?"
     * @param  string  $body  one sentence on the consequence
     * @param  string  $button  the specific action: "Delete unit" (never "Yes" / "OK")
     * @param  string|null  $reason  label of a required reason field
     */
    protected function askToConfirm(string $action, string $id, string $title, string $body, string $button, ?string $reason = null, ?string $placeholder = null): void
    {
        $this->confirmReason = '';
        $this->resetErrorBag('confirmReason');
        $this->confirm = compact('action', 'id', 'title', 'body', 'button', 'reason', 'placeholder');
    }

    /**
     * "Keep it", Esc or a click outside: nothing happens.
     */
    public function dismissConfirm(): void
    {
        $this->confirm = null;
        $this->confirmReason = '';
        $this->resetErrorBag('confirmReason');
    }

    public function runConfirmed(): void
    {
        if ($this->confirm === null) {
            return;
        }

        $reason = trim($this->confirmReason);

        if ($this->confirm['reason'] !== null) {
            if ($reason === '') {
                $this->addError('confirmReason', __('confirm.reason_required'));

                return;
            }

            if (mb_strlen($reason) > self::MAX_CONFIRM_REASON) {
                $this->addError('confirmReason', __('settings.errors.too_long', ['max' => self::MAX_CONFIRM_REASON]));

                return;
            }
        }

        ['action' => $action, 'id' => $id] = $this->confirm;

        $this->dismissConfirm();
        $this->confirmed($action, $id, $reason);
    }
}
