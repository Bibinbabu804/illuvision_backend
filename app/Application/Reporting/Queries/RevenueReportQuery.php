<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Settlement\RevenueAnalyticsService;

/**
 * Revenue analytics report.
 *
 * Consumes the canonical revenue analytics read model. It never re-derives
 * revenue distribution or partner commission values.
 */
class RevenueReportQuery implements ReportQuery
{
    public function __construct(
        protected RevenueAnalyticsService $revenueAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        return [
            'overview' => $this->revenueAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'daily_revenue' => $this->revenueAnalytics->dailyRevenue($dateFrom, $dateTo),
            'monthly_revenue' => $this->revenueAnalytics->monthlyRevenue($dateFrom, $dateTo),
            'screen_wise' => $this->revenueAnalytics->screenWiseRevenue($dateFrom, $dateTo),
            'city_wise' => $this->revenueAnalytics->cityWiseRevenue($dateFrom, $dateTo),
            'referral_impact' => $this->revenueAnalytics->referralImpact($dateFrom, $dateTo),
        ];
    }
}
