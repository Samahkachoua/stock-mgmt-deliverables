<?php

namespace App\Support\Transactions;

use App\Models\TransactionType;

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
     * Translated text for this type, e.g. label('title') → "Issues".
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
     * Today that is Opening Balance, Receive and Issue (App\Services\Stock\PostingService).
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
     * Receive / Issue, or a transfer In transit. Damage and Adjustment can't: a
     * wrong count is corrected by counting again.
     */
    public function cancellable(): bool
    {
        return $this->postsDirectly() || $this->dispatchReceive();
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
     * Optional list page columns: total (default) and reason.
     */
    public function hasListColumn(string $column): bool
    {
        return in_array($column, $this->config['list_columns'] ?? ['total'], true);
    }

    public function hasLineColumn(string $column): bool
    {
        return in_array($column, $this->config['line_columns'], true);
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
