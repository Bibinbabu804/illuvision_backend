<?php

namespace App\Application\Reporting;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Queries\BookingReportQuery;
use App\Application\Reporting\Queries\CampaignReportQuery;
use App\Application\Reporting\Queries\CategoryReportQuery;
use App\Application\Reporting\Queries\CityReportQuery;
use App\Application\Reporting\Queries\OverviewQuery;
use App\Application\Reporting\Queries\ReferralReportQuery;
use App\Application\Reporting\Queries\RevenueReportQuery;
use App\Application\Reporting\Queries\RewardReportQuery;
use App\Application\Reporting\Queries\ScreenProviderReportQuery;
use App\Application\Reporting\Queries\ScreenReportQuery;
use InvalidArgumentException;

/**
 * Single resolution point for the reporting query layer.
 *
 * The report type keys are the externally visible report identifiers used by the
 * reports API and by the /reports/export `type` parameter.
 */
class ReportQueryRegistry
{
    /**
     * @var array<string, class-string<ReportQuery>>
     */
    protected const QUERIES = [
        'overview' => OverviewQuery::class,
        'revenue' => RevenueReportQuery::class,
        'bookings' => BookingReportQuery::class,
        'screens' => ScreenReportQuery::class,
        'screen-providers' => ScreenProviderReportQuery::class,
        'cities' => CityReportQuery::class,
        'campaigns' => CampaignReportQuery::class,
        'referrals' => ReferralReportQuery::class,
        'categories' => CategoryReportQuery::class,
        'rewards' => RewardReportQuery::class,
    ];

    /**
     * @var array<string, ReportQuery>
     */
    protected array $resolved = [];

    public function __construct(
        protected OverviewQuery $overview,
        protected RevenueReportQuery $revenue,
        protected BookingReportQuery $bookings,
        protected ScreenReportQuery $screens,
        protected ScreenProviderReportQuery $screenProviders,
        protected CityReportQuery $cities,
        protected CampaignReportQuery $campaigns,
        protected ReferralReportQuery $referrals,
        protected CategoryReportQuery $categories,
        protected RewardReportQuery $rewards,
    ) {}

    /**
     * Resolve the query responsible for a report type.
     */
    public function get(string $type): ReportQuery
    {
        if (! isset(self::QUERIES[$type])) {
            throw new InvalidArgumentException("Unknown report type [{$type}].");
        }

        return $this->resolved[$type] ??= $this->{$this->propertyFor($type)};
    }

    /**
     * Report types that have a query bound to them.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys(self::QUERIES);
    }

    public function has(string $type): bool
    {
        return isset(self::QUERIES[$type]);
    }

    /**
     * Map a report type key onto its injected constructor property.
     */
    protected function propertyFor(string $type): string
    {
        return match ($type) {
            'overview' => 'overview',
            'revenue' => 'revenue',
            'bookings' => 'bookings',
            'screens' => 'screens',
            'screen-providers' => 'screenProviders',
            'cities' => 'cities',
            'campaigns' => 'campaigns',
            'referrals' => 'referrals',
            'categories' => 'categories',
            'rewards' => 'rewards',
        };
    }
}
