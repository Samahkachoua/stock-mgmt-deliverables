<?php

namespace App\Mail;

use App\Support\Auth\PasswordReset;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The "Reset password" link. Not queued on its own: SendPasswordResetLink (a
 * queued job) sends it, so the link's token never sits in the jobs table.
 */
class PasswordResetLinkEmail extends Mailable
{
    public function __construct(public readonly string $name, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.mail.reset.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset', text: 'mail.password-reset-text', with: [
            'name' => $this->name,
            'url' => $this->url,
            'minutes' => PasswordReset::expiresIn(),
        ]);
    }
}
