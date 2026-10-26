<?php

namespace App\Support\Transactions;

/**
 * The one place that turns a transaction_status.code into what the UI shows
 * (config/transactions.php `statuses`, and a type's own `status_labels`).
 * There is no Draft in the UI.
 */
final class Statuses
{
    /** Statuses only a type with dispatch/receive or delivery steps can reach. */
    private const DISPATCH_RECEIVE = ['DISPATCHED'];

    /** Statuses only a type with delivery steps can reach (Delivery note). */
    private const DELIVERY_STEPS = ['CONFIRMED'];

    /** Statuses only a type with an approval threshold can reach. */
    private const APPROVAL = ['PENDING_APPROVAL', 'REJECTED'];

    /**
     * Statuses offered in filters, code => translated label. Given a type, only
     * those its transaction_type flags let it reach: no In transit without
     * dispatch/receive (or delivery steps), no Confirmed without delivery
     * steps, no Pending approval or Rejected without approval.
     *
     * @return array<string, string>
     */
    public static function options(?TypeUi $type = null): array
    {
        return collect(config('transactions.statuses'))
            ->reject(fn (array $status, string $code) => $type !== null && (
                (! $type->dispatchReceive() && ! $type->deliverySteps() && in_array($code, self::DISPATCH_RECEIVE, true))
                || (! $type->deliverySteps() && in_array($code, self::DELIVERY_STEPS, true))
                || (! $type->supportsApproval() && in_array($code, self::APPROVAL, true))
            ))
            ->map(fn (array $status, string $code) => self::label($code, $type))
            ->all();
    }

    public static function label(string $code, ?TypeUi $type = null): string
    {
        $status = config('transactions.statuses.'.$code);
        $label = $type?->statusLabel($code) ?? $status['label'] ?? null;

        return $label ? __('transactions.status.'.$label) : $code;
    }

    /**
     * Badge colour classes from config('ui.statuses'); neutral for codes the UI does not map.
     */
    public static function classes(string $code): string
    {
        return config('ui.statuses.'.(config('transactions.statuses.'.$code.'.token') ?? 'cancelled'));
    }
}
