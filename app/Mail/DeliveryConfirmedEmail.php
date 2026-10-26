<?php

namespace App\Mail;

use App\Models\Product;
use App\Support\Batches;
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
 * Tells a branch's manager (customer_branch.manager_email) that the Delivery
 * note of their Order was confirmed delivered: who, when, the truck, and per
 * line what was ordered, what was delivered and why less. The manager has no
 * account, so there is no link. Queued after the delivery is saved; built in
 * the company's default language when sent (App\Support\Deliveries\DeliveryNotifier).
 */
class DeliveryConfirmedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var array<string, mixed>|null */
    private ?array $note = null;

    public function __construct(public readonly string $transactionId)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->note()['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.delivery-confirmed', text: 'mail.delivery-confirmed-text', with: ['note' => $this->note()]);
    }

    /**
     * What the email shows, read when it is sent.
     *
     * @return array<string, mixed>
     */
    private function note(): array
    {
        if ($this->note !== null) {
            return $this->note;
        }

        $header = DB::table('stock_transaction as t')
            ->join('customer as c', 'c.id', '=', 't.customer_id')
            ->join('customer_branch as b', 'b.id', '=', 't.customer_branch_id')
            ->join('warehouse as w', 'w.id', '=', 't.warehouse_id')
            ->join('stock_draft as d', 'd.id', '=', 't.draft_id')
            ->leftJoin('app_user as u', 'u.id', '=', 't.delivered_by')
            ->where('t.id', $this->transactionId)
            ->first([
                't.doc_no', 't.delivered_at', 't.reference_note', 't.driver_name', 't.driver_phone', 't.truck_no',
                'c.name as customer', 'b.name as branch', 'w.name as warehouse', 'd.doc_no as order', 'u.full_name as by',
            ]);

        $delivered = CarbonImmutable::parse($header->delivered_at);
        $params = ['reference' => $header->doc_no, 'order' => $header->order, 'branch' => $header->branch, 'customer' => $header->customer];

        return $this->note = [
            'subject' => __('deliveries.mail.subject', $params),
            'intro' => __('deliveries.mail.intro', $params + ['date' => Format::dateTime($delivered)]),
            'facts' => array_filter([
                __('deliveries.mail.customer') => $header->customer,
                __('deliveries.mail.branch') => $header->branch,
                __('deliveries.mail.order') => $header->order,
                __('deliveries.mail.delivery_note') => $header->doc_no,
                __('deliveries.mail.warehouse') => $header->warehouse,
                __('deliveries.mail.delivered_on') => Format::dateTime($delivered),
                __('deliveries.mail.confirmed_by') => $header->by,
                __('deliveries.mail.driver') => trim(implode(' · ', array_filter([$header->driver_name, $header->driver_phone]))),
                __('deliveries.mail.truck') => $header->truck_no,
                __('deliveries.mail.note') => $header->reference_note,
            ], fn ($value) => filled($value)),
            'lines' => $this->lines(),
            'why' => __('deliveries.mail.why', $params),
        ];
    }

    /**
     * Per line: item, lot, ordered, delivered, why less (in the line's unit).
     * A line delivered instead of another says which.
     *
     * @return list<array{item: string, code: string, lot: string, ordered: string, delivered: string, note: string}>
     */
    private function lines(): array
    {
        $rows = DB::table('stock_transaction_line as l')
            ->join('unit as u', 'u.id', '=', 'l.unit_id')
            ->where('l.transaction_id', $this->transactionId)
            ->orderBy('l.line_no')
            ->get(['l.id', 'l.product_id', 'l.qty_entered', 'l.qty_base', 'l.qty_received', 'l.shortage_reason', 'l.expiry_date', 'l.batch_no', 'l.replaces_line_id', 'u.code as unit']);

        $products = Product::withoutGlobalScopes()->with(['baseUnit', 'units.unit'])->findMany($rows->pluck('product_id')->unique())->keyBy('id');
        $byId = $rows->keyBy('id');

        return $rows->map(function ($row) use ($products, $byId) {
            $product = $products[$row->product_id];
            $converter = $product->converter();
            $unit = Format::unit($row->unit);
            $replaced = $row->replaces_line_id === null ? null : $byId->get($row->replaces_line_id);
            $delivered = $row->qty_received === null ? (float) $row->qty_base : (float) $row->qty_received;

            return [
                'item' => $product->name(),
                'code' => $product->sku,
                'lot' => $row->expiry_date === null ? '' : Batches::text($row->expiry_date, $row->batch_no),
                // A line delivered instead of another was not ordered.
                'ordered' => $replaced === null ? Format::qty((float) $row->qty_entered).' '.$unit : '',
                'delivered' => Format::qty($converter->fromBase($delivered, $row->unit)).' '.$unit,
                'note' => match (true) {
                    $replaced !== null => __('deliveries.mail.instead_of', ['item' => $products[$replaced->product_id]->name()]),
                    $row->shortage_reason !== null => __('deliveries.reasons.'.$row->shortage_reason),
                    default => '',
                },
            ];
        })->values()->all();
    }
}
