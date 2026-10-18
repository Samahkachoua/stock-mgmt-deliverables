<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * Leaving a form with unsaved changes asks first, in the shared confirmation
 * modal (use with ConfirmsActions; view: x-data="leaveGuard" on the root,
 * resources/js/app.js). Cancel, the back link and every other link inside the
 * app go through leave(): straight to the page when nothing changed, otherwise
 * "Discard your changes?" with "Keep it" focused.
 *
 * "Changed" means different from the form as it opened (formState() then and
 * now), so typing something and deleting it again changes nothing. Saving or
 * deleting calls allowLeaving() before its redirect, so that never asks.
 */
trait WarnsBeforeLeaving
{
    /** Whether something differs from the form as it opened; read by the browser before it leaves. */
    #[Locked]
    public bool $unsaved = false;

    /** formState() as the form opened (a hash). */
    #[Locked]
    public string $savedState = '';

    /** Leaving on purpose in this request (after saving, deleting or "Discard changes"). */
    private bool $leaving = false;

    /**
     * What the user can change, normalised the way it would be saved.
     *
     * @return array<string, mixed>
     */
    abstract protected function formState(): array;

    /**
     * The question for this form: title naming the record, one sentence, the red button.
     *
     * @return array{title: string, body: string, button: string}
     */
    abstract protected function leaveQuestion(): array;

    /** Call at the end of mount(), once the form is filled. */
    protected function rememberSavedState(): void
    {
        $this->savedState = $this->stateHash();
    }

    /** Call before redirecting after a save or a delete. */
    protected function allowLeaving(): void
    {
        $this->leaving = true;
    }

    public function hasUnsavedChanges(): bool
    {
        return ! $this->leaving && $this->stateHash() !== $this->savedState;
    }

    public function dehydrateWarnsBeforeLeaving(): void
    {
        $this->unsaved = $this->hasUnsavedChanges();
    }

    /**
     * The browser is about to go to $to (a link inside the app).
     */
    public function leave(string $to): void
    {
        if (! $this->hasUnsavedChanges()) {
            $this->leaveTo($to);

            return;
        }

        ['title' => $title, 'body' => $body, 'button' => $button] = $this->leaveQuestion();

        // The destination travels in the (locked) confirmation, so it can't be swapped.
        $this->askToConfirm('leave', $this->localPath($to), $title, $body, $button);
    }

    /** "Discard changes" was confirmed. */
    protected function leaveTo(string $to): void
    {
        $this->allowLeaving();
        $this->redirect($this->localPath($to), navigate: true);
    }

    /**
     * Only a page of this app: the path (and query) of a URL on this host;
     * anything else goes to the home page.
     */
    private function localPath(string $to): string
    {
        $url = parse_url($to);
        $host = $url['host'] ?? null;

        if ($url === false || ($host !== null && $host !== request()->getHost()) || ! str_starts_with($url['path'] ?? '/', '/')) {
            return '/';
        }

        return ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
    }

    private function stateHash(): string
    {
        return md5((string) json_encode($this->formState()));
    }
}
