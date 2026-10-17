<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Booking\BookingAnalyticsService;

/**
 * Booking analytics report: status breakdown, volume trends, approval metrics.
 */
class BookingReportQuery implements ReportQuery
{
    public function __construct(
        protected BookingAnalyticsService $bookingAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        return [
            'overview' => $this->bookingAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'daily_volumes' => $this->bookingAnalytics->dailyVolumes($dateFrom, $dateTo),
            'booking_trends' => $this->bookingAnalytics->bookingTrends($dateFrom, $dateTo, 'month'),
            'approval_metrics' => $this->bookingAnalytics->approvalMetrics(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'source_breakdown' => $this->bookingAnalytics->sourceBreakdown($dateFrom, $dateTo),
        ];
    }
}
