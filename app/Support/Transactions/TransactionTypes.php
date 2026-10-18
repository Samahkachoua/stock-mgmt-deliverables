<?php

namespace App\Support\Transactions;

use App\Models\TransactionType;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The transaction types that have screens: configured in config/transactions.php
 * AND active in the transaction_type table. Rows are cached; call flush() after
 * changing transaction_type.
 */
final class TransactionTypes
{
    public const CACHE_KEY = 'transactions.types';

    /** @var array<string, TypeUi>|null */
    private static ?array $resolved = null;

    /**
     * @return array<string, TypeUi> keyed by code, in display_order
     */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => TransactionType::where('active', true)
            ->orderBy('display_order')
            ->get()
            ->keyBy('code')
            ->all());

        $types = [];

        foreach ($rows as $code => $row) {
            if ($config = config('transactions.types.'.$code)) {
                $types[$code] = new TypeUi($code, $config, $row);
            }
        }

        return self::$resolved = $types;
    }

    public static function get(string $code): TypeUi
    {
        return self::all()[$code] ?? throw new NotFoundHttpException("No screens for transaction type [$code].");
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::$resolved = null;
    }
}
