<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Services\Settlement\RevenueAnalyticsService;
use App\Services\Campaign\ScreenAnalyticsService;

/**
 * City performance report: screen inventory and revenue merged per city.
 */
class CityReportQuery implements ReportQuery
{
    public function __construct(
        protected ScreenAnalyticsService $screenAnalytics,
        protected RevenueAnalyticsService $revenueAnalytics,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        $cities = $this->screenAnalytics->cityPerformance($dateFrom, $dateTo);
        $cityRevenue = $this->revenueAnalytics->cityWiseRevenue($dateFrom, $dateTo);

        // Merge data
        $revenueMap = collect($cityRevenue)->keyBy('city_id');

        $merged = array_map(function ($city) use ($revenueMap) {
            $revData = $revenueMap->get($city['city_id']);

            return array_merge($city, [
                'revenue' => $revData['revenue'] ?? 0,
                'avg_revenue_per_screen' => $revData['revenue'] && $city['screen_count'] > 0
                    ? round($revData['revenue'] / $city['screen_count'], 2)
                    : 0,
            ]);
        }, $cities);

        return [
            'city_performance' => $merged,
            'total_cities' => count($merged),
            'top_cities' => array_slice($merged, 0, 5),
        ];
    }
}
