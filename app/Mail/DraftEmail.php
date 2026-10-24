<?php

namespace App\Mail;

use App\Models\StockDraft;
use App\Support\Drafts\DraftTypes;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Tells someone who can confirm a kind of draft that one was added, changed
 * or cancelled ("New order ORD-2026-000045 from Acme Foods", "New draft
 * receipt DRR-2026-000012 from Acme Foods"), with a link to it. Confirming
 * happens only in the app (it checks stock again); the email changes nothing. Queued after the save, built in the recipient's language
 * when sent.
 */
class DraftEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const CANCELLED = 'cancelled';

    /** @var array<string, mixed>|null */
    private ?array $draft = null;

    /**
     * @param  self::CREATED|self::UPDATED|self::CANCELLED  $event
     */
    public function __construct(public readonly string $draftId, public readonly string $event)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->draft()['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.draft', text: 'mail.draft-text', with: ['draft' => $this->draft()]);
    }

    /**
     * What the email shows, read when it is sent.
     *
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        if ($this->draft !== null) {
            return $this->draft;
        }

        $draft = StockDraft::withoutGlobalScopes()->findOrFail($this->draftId);
        $kind = DraftTypes::get($draft->transaction_type);
        $header = DB::table('stock_draft as d')
            ->join('customer as c', 'c.id', '=', 'd.customer_id')
            ->join('customer_branch as b', 'b.id', '=', 'd.customer_branch_id')
            // Who did it: the creator for a new draft, else the last person who changed or cancelled it.
            ->join('app_user as u', 'u.id', '=', DB::raw($this->event === self::CREATED ? 'd.created_by' : 'coalesce(d.updated_by, d.created_by)'))
            ->where('d.id', $draft->id)
            ->first(['c.name as customer', 'b.name as branch', 'u.full_name as by', 'd.document_date', 'd.reference_note', 'd.cancel_reason']);
        $lines = DB::table('stock_draft_line as l')
            ->join('product as p', 'p.id', '=', 'l.product_id')
            ->join('unit as u', 'u.id', '=', 'p.base_unit_id')
            ->where('l.draft_id', $draft->id)
            ->get(['l.qty_base', 'u.code as base_unit']);

        return $this->draft = [
            'subject' => $kind->mailText('subject.'.$this->event, ['reference' => $draft->doc_no, 'customer' => $header->customer]),
            'intro' => $kind->mailText('intro.'.$this->event, ['name' => $header->by]),
            'branch_label' => $kind->mailText('branch'),
            'open' => $kind->mailText('open'),
            'why' => $kind->mailText('why'),
            'reference' => $draft->doc_no,
            'customer' => $header->customer,
            'branch' => $header->branch,
            'warehouse' => (string) DB::table('warehouse')->where('id', $draft->warehouse_id)->value('name'),
            'date' => Format::date(CarbonImmutable::parse($header->document_date)),
            'summary' => Format::itemsTotal($lines->count(), (float) $lines->sum('qty_base'), $lines->pluck('base_unit')->all()),
            'note' => $header->reference_note,
            'reason' => $this->event === self::CANCELLED ? $header->cancel_reason : null,
            'url' => $kind->route('show', ['reference' => $draft->doc_no]),
        ];
    }
}
