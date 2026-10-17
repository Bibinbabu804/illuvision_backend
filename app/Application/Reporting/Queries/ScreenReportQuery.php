<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Campaign\ScreenAnalyticsService;

/**
 * Screen performance report.
 *
 * Note: the full $filters array (not just the resolved period) is forwarded to
 * ScreenAnalyticsService::screenPerformance() because that method reads
 * additional keys such as `status` for its available/sold_out/maintenance
 * branches. Narrowing the forwarded array would silently drop that filtering.
 */
class ScreenReportQuery implements ReportQuery
{
    public function __construct(
        protected ScreenAnalyticsService $screenAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        return [
            'overview' => $this->screenAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'screen_performance' => $this->screenAnalytics->screenPerformance($dateFrom, $dateTo, $filters),
            'utilization_distribution' => $this->screenAnalytics->utilizationDistribution($dateFrom, $dateTo),
        ];
    }
}
