<?php

namespace App\Models;

use App\Models\Concerns\CustomerScoped;
use App\Models\Concerns\HasNumericKey;
use App\Models\Scopes\DraftScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A draft receipt / order for one warehouse: lines that touch no
 * stock until the draft is confirmed (App\Services\Stock\PostingService::confirmDraft).
 * Who sees which drafts: CustomerScope and DraftScope.
 *
 * @property string $id
 * @property string $doc_no
 * @property string $transaction_type what confirming creates: IN or OUT
 * @property string $status DRAFT, POSTED (confirmed) or CANCELLED
 * @property string $customer_id
 * @property string $customer_branch_id
 * @property string $warehouse_id where the stock comes in / goes out when confirmed
 * @property string $created_by
 * @property string|null $confirmed_by
 * @property string $updated_at
 */
class StockDraft extends Model
{
    use CustomerScoped, HasNumericKey;

    protected $table = 'stock_draft';

    protected static function booted(): void
    {
        static::addGlobalScope(new DraftScope);
    }

    /**
     * @return HasMany<StockDraftLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDraftLine::class, 'draft_id')->orderBy('line_no');
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }
}
