<?php

namespace App\Support\Drafts;

use App\Support\Transactions\TransactionTypes;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The kinds of draft that have screens: configured in config/transactions.php
 * `drafts` for a transaction type that has screens and moves stock one way
 * (only such a type can be posted at once per warehouse when confirming).
 */
final class DraftTypes
{
    /** @var array<string, DraftUi>|null */
    private static ?array $resolved = null;

    /**
     * @return array<string, DraftUi> keyed by the transaction type code they create
     */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $drafts = [];
        $types = TransactionTypes::all();

        foreach (config('transactions.drafts') as $code => $config) {
            if (isset($types[$code]) && $types[$code]->postsDirectly()) {
                $drafts[$code] = new DraftUi($code, $config, $types[$code]);
            }
        }

        return self::$resolved = $drafts;
    }

    public static function get(string $code): DraftUi
    {
        return self::all()[$code] ?? throw new NotFoundHttpException("No draft screens for transaction type [$code].");
    }

    public static function flush(): void
    {
        self::$resolved = null;
    }

    /**
     * Label of a draft status (stock_draft.status) for the UI.
     */
    public static function statusLabel(string $code): string
    {
        $status = config('transactions.draft_statuses.'.$code);

        return $status ? __('drafts.status.'.$status['label']) : $code;
    }

    /**
     * Badge colour classes of a draft status.
     */
    public static function statusClasses(string $code): string
    {
        return config('ui.statuses.'.(config('transactions.draft_statuses.'.$code.'.token') ?? 'cancelled'));
    }

    /**
     * Statuses offered in the list filter, code => label.
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return collect(config('transactions.draft_statuses'))
            ->map(fn (array $status) => __('drafts.status.'.$status['label']))
            ->all();
    }
}
