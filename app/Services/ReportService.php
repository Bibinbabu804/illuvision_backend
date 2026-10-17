<?php

namespace App\Services;

use App\Application\Reporting\ReportQueryRegistry;

/**
 * Backward-compatible facade over the reporting query layer.
 *
 * The report implementations now live in App\Application\Reporting\Queries. This
 * class is retained because it is part of the internal service surface consumed
 * by existing callers and tests (for example
 * tests/Feature/CampaignDurationConsistencyTest.php), and removing it would be a
 * breaking change with no architectural benefit.
 *
 * New code should depend on ReportQueryRegistry or the individual query classes
 * directly.
 *
 * @deprecated Use App\Application\Reporting\ReportQueryRegistry instead.
 */
class ReportService
{
    public function __construct(
        protected ReportQueryRegistry $reports,
    ) {}

    /**
     * Get the overview dashboard data with all KPI metrics.
     */
    public function getOverview(array $filters = []): array
    {
        return $this->reports->get('overview')->execute($filters);
    }

    /**
     * Get revenue analytics with charts and breakdowns.
     */
    public function getRevenueReport(array $filters = []): array
    {
        return $this->reports->get('revenue')->execute($filters);
    }

    /**
     * Get booking analytics with status breakdown and trends.
     */
    public function getBookingReport(array $filters = []): array
    {
        return $this->reports->get('bookings')->execute($filters);
    }

    /**
     * Get screen performance report.
     */
    public function getScreenReport(array $filters = []): array
    {
        return $this->reports->get('screens')->execute($filters);
    }

    /**
     * Get city performance report.
     */
    public function getCityReport(array $filters = []): array
    {
        return $this->reports->get('cities')->execute($filters);
    }

    /**
     * Get campaign analytics report.
     */
    public function getCampaignReport(array $filters = []): array
    {
        return $this->reports->get('campaigns')->execute($filters);
    }

    /**
     * Get referral and partner analytics report.
     */
    public function getReferralReport(array $filters = []): array
    {
        return $this->reports->get('referrals')->execute($filters);
    }

    /**
     * Get advertisement category report.
     */
    public function getCategoryReport(array $filters = []): array
    {
        return $this->reports->get('categories')->execute($filters);
    }

    /**
     * Get rewards & loyalty program analytics report.
     */
    public function getRewardReport(array $filters = []): array
    {
        return $this->reports->get('rewards')->execute($filters);
    }

    /**
     * Get screen provider financial report with provider, screen, and booking breakdowns.
     */
    public function getScreenProviderReport(array $filters = []): array
    {
        return $this->reports->get('screen-providers')->execute($filters);
    }
}
