<?php

namespace App\Reports;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reports, in the order the Reports page lists them. Stock on hand is the
 * Stock page itself and Stock by expiry has its own sidebar page; they are here
 * for their exports.
 */
final class Reports
{
    /** @var list<class-string<Report>> */
    public const ALL = [
        StockOnHandReport::class,
        StockByExpiryReport::class,
        StockCardReport::class,
        ExpiryReport::class,
        DormantStockReport::class,
        DamageReport::class,
        AdjustmentsReport::class,
        TransfersInTransitReport::class,
        TransferShortagesReport::class,
        DeliveryDifferencesReport::class,
        AuditLogReport::class,
    ];

    /**
     * @return array<string, Report> keyed by URL segment
     */
    public static function all(): array
    {
        $reports = [];

        foreach (self::ALL as $class) {
            $report = new $class;
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public static function get(string $key): Report
    {
        return self::all()[$key] ?? throw new NotFoundHttpException("No report [$key].");
    }

    /**
     * The reports listed on the Reports page that the user may open (the Stock
     * and Stock by expiry pages are reached from the sidebar).
     *
     * @return array<string, Report>
     */
    public static function listedFor(User $user): array
    {
        return array_filter(
            self::all(),
            fn (Report $report) => ! $report instanceof StockOnHandReport && ! $report instanceof StockByExpiryReport && $user->hasPermission($report->permission()),
        );
    }
}
