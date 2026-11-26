<?php

namespace App\Mail;

use App\Support\Notifications\NotificationView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;

/**
 * The email copy of an in-app notification (approval requested, approved,
 * rejected): one line, the reason if any, and a link that opens it in the
 * app. Queued after commit, built in the recipient's language when sent.
 */
class NotificationEmail extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @var array<string, mixed>|null */
    private ?array $notification = null;

    public function __construct(public readonly string $notificationId)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification()['message']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification', text: 'mail.notification-text', with: ['notification' => $this->notification()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function notification(): array
    {
        if ($this->notification !== null) {
            return $this->notification;
        }

        $row = DB::table('notification as n')
            ->leftJoin('app_user as r', 'r.id', '=', 'n.resolved_by')
            ->where('n.id', $this->notificationId)
            ->first(['n.*', 'r.full_name as resolver_name']);

        abort_if($row === null, 404);

        // Why this person gets it: an approval waiting for them, or the result of their own request.
        $why = $row->event === 'approval.requested' ? 'approvals' : 'approval_results';

        return $this->notification = NotificationView::present($row) + ['why' => __('notifications.mail.why.'.$why)];
    }
}
