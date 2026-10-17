<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\GrowthRate;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Booking\BookingAnalyticsService;
use App\Services\Settlement\RevenueAnalyticsService;
use App\Services\Campaign\ScreenAnalyticsService;

/**
 * Dashboard overview: KPI block, chart series and summary totals.
 *
 * Composes existing canonical analytics services. It does not recalculate any
 * pricing, payment, commission or settlement value.
 */
class OverviewQuery implements ReportQuery
{
    public function __construct(
        protected RevenueAnalyticsService $revenueAnalytics,
        protected BookingAnalyticsService $bookingAnalytics,
        protected ScreenAnalyticsService $screenAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        // Determine comparison period (same length, before current)
        $daysDiff = \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo));
        $prevDateTo = \Carbon\Carbon::parse($dateFrom)->subDay()->format('Y-m-d');
        $prevDateFrom = \Carbon\Carbon::parse($prevDateTo)->subDays($daysDiff)->format('Y-m-d');

        // Current period KPIs
        $revenue = $this->revenueAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $bookingOverview = $this->bookingAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $screenOverview = $this->screenAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        // Previous period for comparison
        $prevRevenue = $this->revenueAnalytics->overview(['date_from' => $prevDateFrom, 'date_to' => $prevDateTo]);
        $prevBookingOverview = $this->bookingAnalytics->overview(['date_from' => $prevDateFrom, 'date_to' => $prevDateTo]);

        // Calculate growth rates
        $revenueGrowth = GrowthRate::between($prevRevenue['total_revenue'], $revenue['total_revenue']);
        $bookingGrowth = GrowthRate::between($prevBookingOverview['total_bookings'], $bookingOverview['total_bookings']);

        // Charts data
        $revenueTrend = $this->revenueAnalytics->dailyRevenue($dateFrom, $dateTo);
        $bookingTrend = $this->bookingAnalytics->dailyVolumes($dateFrom, $dateTo);

        $topCities = $this->revenueAnalytics->cityWiseRevenue($dateFrom, $dateTo);

        return [
            'kpis' => [
                'total_revenue' => $revenue['total_revenue'],
                'revenue_growth' => $revenueGrowth,
                'total_bookings' => $bookingOverview['total_bookings'],
                'booking_growth' => $bookingGrowth,
                'active_campaigns' => $bookingOverview['active_campaigns'],
                'pending_approvals' => $bookingOverview['pending_review'],
                'average_occupancy' => $screenOverview['occupancy_rate'],
                'referral_revenue' => $revenue['referral_discounts'],
            ],
            'charts' => [
                'revenue_trends' => $revenueTrend,
                'booking_trends' => $bookingTrend,
                'screen_utilization' => $this->screenAnalytics->utilizationDistribution($dateFrom, $dateTo),
                'top_cities' => array_slice($topCities, 0, 10),
            ],
            'summary' => [
                'total_revenue' => $revenue['total_revenue'],
                'total_bookings' => $bookingOverview['total_bookings'],
                'total_screens' => $screenOverview['total_screens'],
                'active_screens' => $screenOverview['active_screens'],
                'total_discounts' => $revenue['total_discounts'],
                'referral_discounts' => $revenue['referral_discounts'],
                'total_commissions' => $revenue['total_commissions'],
                'net_revenue' => $revenue['net_revenue'],
            ],
        ];
    }
}
