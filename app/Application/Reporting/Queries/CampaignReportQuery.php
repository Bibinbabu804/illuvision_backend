<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\DurationLabelFormatter;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Booking\BookingAnalyticsService;
use App\Services\Campaign\ScreenAnalyticsService;

/**
 * Campaign analytics report: per-campaign rows plus an aggregate summary.
 *
 * Duration figures are expressed in canonical minutes and rendered through the
 * shared DurationLabelFormatter, matching the legacy ReportService output that
 * CampaignDurationConsistencyTest asserts against.
 */
class CampaignReportQuery implements ReportQuery
{
    public function __construct(
        protected ScreenAnalyticsService $screenAnalytics,
        protected BookingAnalyticsService $bookingAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        $campaigns = $this->screenAnalytics->campaignAnalytics($dateFrom, $dateTo);
        $bookingOverview = $this->bookingAnalytics->overview(['date_from' => $dateFrom, 'date_to' => $dateTo]);

        $activeCampaigns = array_filter(
            $campaigns,
            fn ($c) => in_array($c['status'], ['approved', 'scheduled', 'running'])
        );
        $completedCampaigns = array_filter(
            $campaigns,
            fn ($c) => in_array($c['status'], ['completed'])
        );

        $avgDurationMinutes = count($campaigns) > 0
            ? (int) round(array_sum(array_column($campaigns, 'duration_minutes')) / count($campaigns))
            : 0;

        $totalDurationMinutes = count($campaigns) > 0
            ? (int) array_sum(array_column($campaigns, 'duration_minutes'))
            : 0;

        $avgDurationDays = count($campaigns) > 0
            ? round(array_sum(array_column($campaigns, 'duration_days')) / count($campaigns), 1)
            : 0;

        $totalCampaignValue = array_sum(array_column($activeCampaigns, 'final_amount'));

        return [
            'campaigns' => $campaigns,
            'active_campaigns' => array_values($activeCampaigns),
            'completed_campaigns' => array_values($completedCampaigns),
            'summary' => [
                'total_campaigns' => count($campaigns),
                'active_count' => count($activeCampaigns),
                'completed_count' => count($completedCampaigns),
                'average_duration_minutes' => $avgDurationMinutes,
                'average_duration_days' => $avgDurationDays,
                'average_duration_label' => DurationLabelFormatter::formatMinutes($avgDurationMinutes),
                'total_duration_minutes' => $totalDurationMinutes,
                'total_duration_label' => DurationLabelFormatter::formatMinutes($totalDurationMinutes),
                'total_value' => $totalCampaignValue,
                'campaign_share_pct' => $bookingOverview['total_bookings'] > 0
                    ? round((count($campaigns) / $bookingOverview['total_bookings']) * 100, 1)
                    : 0,
            ],
        ];
    }
}
