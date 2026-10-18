<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells an administrator that the nightly check found stock balances that
 * don't match the movements (stock:reconcile). Sent in the admin's language.
 */
class StockMismatchFound extends Mailable
{
    use Queueable;

    /** How many differences the email lists; the log has them all. */
    public const SHOWN = 50;

    /**
     * @param  list<array{warehouse: string, customer: string, item: string, expiry: string|null, balance: float, movements: float}>  $mismatches
     */
    public function __construct(public readonly array $mismatches) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('reconcile.mail.subject', ['count' => count($this->mismatches), 'app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.stock-mismatch', with: [
            'shown' => array_slice($this->mismatches, 0, self::SHOWN),
            'total' => count($this->mismatches),
        ]);
    }
}
