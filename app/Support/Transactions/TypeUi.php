<?php

namespace App\Support\Transactions;

use App\Models\TransactionType;
use App\Support\Format;
use Carbon\CarbonImmutable;

/**
 * Everything a transaction screen needs to know about one type: presentation
 * from config/transactions.php, behaviour flags from the transaction_type row.
 */
final class TypeUi
{
    /**
     * @param  array<string, mixed>  $config  config('transactions.types.<code>')
     */
    public function __construct(
        public readonly string $code,
        private readonly array $config,
        private readonly TransactionType $row,
    ) {}

    public function slug(): string
    {
        return $this->config['slug'];
    }

    public function token(): string
    {
        return $this->config['token'];
    }

    public function icon(): string
    {
        return $this->config['icon'];
    }

    public function prefix(): string
    {
        return $this->config['prefix'];
    }

    public function viewPermission(): string
    {
        return $this->config['view'];
    }

    /**
     * The gate of the list, details and print pages: the view permission, or
     * creating / approving the type (AppServiceProvider).
     */
    public function viewGate(): string
    {
        return 'documents.'.$this->slug();
    }

    public function createPermission(): string
    {
        return $this->config['create'];
    }

    public function approvePermission(): ?string
    {
        return $this->config['approve'] ?? null;
    }

    /**
     * Who may cancel. A transfer In transit is taken back by its sender (anyone
     * who may dispatch, in the source warehouse); other documents need
     * transaction.cancel.
     */
    public function cancelPermission(): string
    {
        return $this->dispatchReceive() ? $this->createPermission() : 'transaction.cancel';
    }

    /**
     * @return array{icon: string, soft: string, strong: string}
     */
    public function colours(): array
    {
        return config('ui.types.'.$this->token());
    }

    /**
     * Translated text for this type, e.g. label('title') → "Delivery notes".
     *
     * @param  array<string, mixed>  $replace
     */
    public function label(string $key, array $replace = []): string
    {
        return __('transactions.types.'.$this->config['lang'].'.'.$key, $replace);
    }

    public function route(string $page = 'index', mixed $parameters = []): string
    {
        return route($page === 'index' ? $this->slug() : $this->slug().'.'.$page, $parameters);
    }

    // Behaviour, from the transaction_type table.

    public function requiresDestination(): bool
    {
        return $this->row->requires_destination_warehouse;
    }

    public function dispatchReceive(): bool
    {
        return $this->row->requires_dispatch_receive;
    }

    public function supportsApproval(): bool
    {
        return $this->row->supports_approval_threshold;
    }

    /**
     * IN or OUT for types that move stock one way; null for Adjustment / Transfer.
     */
    public function postingDirection(): ?string
    {
        return $this->row->posting_direction;
    }

    /**
     * Whether expired stock may be taken (Damage). Otherwise it is skipped.
     */
    public function usesExpired(): bool
    {
        return $this->row->uses_expired;
    }

    /**
     * Saving posts at once: one direction, no approval step, no dispatch/receive.
     * Today that is Opening Balance, Receive and Delivery note (App\Services\Stock\PostingService).
     */
    public function postsDirectly(): bool
    {
        return $this->postingDirection() !== null && ! $this->supportsApproval() && ! $this->dispatchReceive();
    }

    /**
     * Lines are counted quantities; each line's direction comes from the
     * difference with the system (Adjustment): no fixed direction, one step.
     */
    public function countsStock(): bool
    {
        return $this->postingDirection() === null && ! $this->dispatchReceive();
    }

    /**
     * Whether a document of this type can be cancelled: posted Opening Balance /
     * Receive / Delivery note, or a transfer In transit. Damage and Adjustment can't: a
     * wrong count is corrected by counting again.
     */
    public function cancellable(): bool
    {
        return $this->postsDirectly() || $this->dispatchReceive();
    }

    /**
     * A line's key (CLAUDE.md rule 12, `line_key`): two lines with the same key
     * may not be on one document. The item, plus its unit and/or its expiry
     * date (expiry-tracked items only, '' when none is chosen yet).
     */
    public function lineKey(string $productId, string $unit, ?string $expiry, bool $tracksExpiry): string
    {
        $parts = $this->config['line_key'] ?? ['unit', 'expiry'];
        $key = [$productId];

        if (in_array('unit', $parts, true)) {
            $key[] = $unit;
        }

        if (in_array('expiry', $parts, true) && $tracksExpiry) {
            $key[] = $expiry ?? '';
        }

        return implode('|', $key);
    }

    /**
     * "Olive Oil 1L (Carton, expiry 12 Mar 2027) is already in row 1 — change
     * the quantity there": translation key and parameters, for a repeated line
     * (the row number goes in `row`).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function duplicateMessage(string $item, string $unit, ?string $expiry, bool $tracksExpiry, int $row): array
    {
        $parts = $this->config['line_key'] ?? ['unit', 'expiry'];
        $withExpiry = in_array('expiry', $parts, true) && $tracksExpiry && $expiry !== null && $expiry !== '';
        $params = ['item' => $item, 'row' => Format::qty($row)];

        if ($withExpiry) {
            $params['expiry'] = Format::date(CarbonImmutable::parse($expiry));
        }

        if (! in_array('unit', $parts, true)) {
            return [$withExpiry ? 'transactions.form.duplicate.count_expiry' : 'transactions.form.duplicate.count', $params];
        }

        return [$withExpiry ? 'transactions.form.duplicate.unit_expiry' : 'transactions.form.duplicate.unit', $params + ['unit' => Format::unit($unit)]];
    }

    /**
     * Whether Save asks first when the document posts at once: always for types
     * that can't be cancelled (Damage, Adjustment), and for those with
     * `confirm_save` (Opening Balance). Sending for approval never asks.
     */
    public function confirmsSave(): bool
    {
        return ! $this->cancellable() || ($this->config['confirm_save'] ?? false);
    }

    /**
     * Header fields besides Customer, Date and Reference note, in display order.
     *
     * @return list<string>
     */
    public function headerFields(): array
    {
        return [
            ...($this->requiresDestination() ? ['to_warehouse'] : []),
            ...$this->config['header_fields'],
        ];
    }

    public function hasHeaderField(string $field): bool
    {
        return in_array($field, $this->headerFields(), true);
    }

    /**
     * Optional list page columns: reason and draft.
     */
    public function hasListColumn(string $column): bool
    {
        return in_array($column, $this->config['list_columns'] ?? [], true);
    }

    public function hasLineColumn(string $column): bool
    {
        return in_array($column, $this->config['line_columns'], true);
    }

    /**
     * May service items (no stock) be lines of this type? (config `services`)
     */
    public function allowsServices(): bool
    {
        return $this->config['services'] ?? false;
    }

    /**
     * "available": quantity may not exceed what is in stock. "in_stock": no maximum.
     */
    public function hint(): string
    {
        return $this->config['hint'];
    }

    public function limitedByStock(): bool
    {
        return $this->hint() === 'available';
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->config['reasons'] ?? [];
    }
}
