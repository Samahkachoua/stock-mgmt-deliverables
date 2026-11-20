<?php

namespace App\Mail;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your password was changed", after a reset or Account › Change password,
 * so the owner hears of it if it wasn't them. Holds no secret, so it is queued.
 */
class PasswordChangedEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $changedAt  ISO 8601, shown in the company time zone when sent
     */
    public function __construct(public readonly string $name, public readonly string $changedAt)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.mail.changed.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-changed', text: 'mail.password-changed-text', with: [
            'name' => $this->name,
            'when' => Format::dateTime(CarbonImmutable::parse($this->changedAt)),
        ]);
    }
}
