<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Screen provider financial report.
 *
 * All monetary figures are summed from the persisted `booking_screens` revenue
 * split columns (gross_revenue, platform_share, provider_share) written by the
 * financial domain at distribution time. This query only aggregates those
 * stored values; it never invokes the revenue distribution or commission
 * engines, so historical financial figures stay historical.
 */
class ScreenProviderReportQuery implements ReportQuery
{
    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        // Base query for booking_screens with provider-owned screens
        $baseQuery = DB::table('booking_screens')
            ->join('screen_providers', 'screen_providers.id', '=', 'booking_screens.screen_provider_id')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('screens', 'screens.id', '=', 'booking_screens.screen_id')
            ->leftJoin('cities', 'cities.id', '=', 'screens.city_id')
            ->leftJoin('countries', 'countries.id', '=', 'screens.country_id')
            ->where('booking_screens.owner_type', 'screen_provider')
            ->whereIn('bookings.status', [
                Booking::STATUS_APPROVED,
                Booking::STATUS_SCHEDULED,
                Booking::STATUS_RUNNING,
                Booking::STATUS_COMPLETED,
            ])
            ->whereBetween('bookings.end_time', [$dateFrom, $dateTo]);

        // Apply additional filters
        if (! empty($filters['screen_provider_id'])) {
            $baseQuery->where('booking_screens.screen_provider_id', $filters['screen_provider_id']);
        }
        if (! empty($filters['screen_id'])) {
            $baseQuery->where('booking_screens.screen_id', $filters['screen_id']);
        }
        if (! empty($filters['country_id'])) {
            $baseQuery->where('screens.country_id', $filters['country_id']);
        }
        if (! empty($filters['city_id'])) {
            $baseQuery->where('screens.city_id', $filters['city_id']);
        }
        if (! empty($filters['settlement_status'])) {
            $baseQuery->where('booking_screens.settlement_status', $filters['settlement_status']);
        }
        if (! empty($filters['business_type'])) {
            $baseQuery->where('screen_providers.business_type', $filters['business_type']);
        }

        // ── Summary KPIs ──
        $totalProviders = (clone $baseQuery)->distinct('booking_screens.screen_provider_id')->count('booking_screens.screen_provider_id');
        $totalProviderRevenue = (float) (clone $baseQuery)->sum('booking_screens.provider_share');
        $totalPlatformRevenue = (float) (clone $baseQuery)->sum('booking_screens.platform_share');
        $totalGrossRevenue = (float) (clone $baseQuery)->sum('booking_screens.gross_revenue');

        // Pending settlement and settled amounts (from booking_screens)
        $pendingSettlement = (float) (clone $baseQuery)
            ->where('booking_screens.settlement_status', 'pending')
            ->sum('booking_screens.provider_share');

        $settledAmount = (float) (clone $baseQuery)
            ->where('booking_screens.settlement_status', 'settled')
            ->sum('booking_screens.provider_share');

        $outstandingLiability = $pendingSettlement;

        $totalOwnedScreens = DB::table('screens')
            ->where('owner_type', 'screen_provider')
            ->whereExists(function ($q) use ($dateFrom, $dateTo) {
                $q->select(DB::raw(1))
                    ->from('booking_screens')
                    ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
                    ->whereColumn('booking_screens.screen_id', 'screens.id')
                    ->whereIn('bookings.status', [
                        Booking::STATUS_APPROVED,
                        Booking::STATUS_SCHEDULED,
                        Booking::STATUS_RUNNING,
                        Booking::STATUS_COMPLETED,
                    ])
                    ->whereBetween('bookings.end_time', [$dateFrom, $dateTo]);
            })
            ->count();

        // ── Provider Breakdown ──
        $providerBreakdown = (clone $baseQuery)
            ->select(
                'screen_providers.id as provider_id',
                'screen_providers.provider_name',
                'screen_providers.business_name',
                'screen_providers.business_type',
                DB::raw('COUNT(DISTINCT booking_screens.screen_id) as owned_screens'),
                DB::raw('COUNT(DISTINCT booking_screens.booking_id) as campaign_count'),
                DB::raw('SUM(booking_screens.gross_revenue) as gross_revenue'),
                DB::raw('SUM(booking_screens.platform_share) as platform_revenue'),
                DB::raw('SUM(booking_screens.provider_share) as provider_revenue'),
                DB::raw("SUM(CASE WHEN booking_screens.settlement_status = 'pending' THEN booking_screens.provider_share ELSE 0 END) as pending_settlement"),
                DB::raw("SUM(CASE WHEN booking_screens.settlement_status = 'settled' THEN booking_screens.provider_share ELSE 0 END) as settled_amount"),
                DB::raw("SUM(CASE WHEN booking_screens.settlement_status = 'pending' THEN booking_screens.provider_share ELSE 0 END) as outstanding_balance")
            )
            ->groupBy('screen_providers.id', 'screen_providers.provider_name', 'screen_providers.business_name', 'screen_providers.business_type')
            ->orderByDesc('provider_revenue')
            ->get()
            ->toArray();

        // ── Screen Breakdown ──
        $screenBreakdown = (clone $baseQuery)
            ->select(
                'screens.id as screen_id',
                'screens.name as screen_name',
                'screens.screen_code',
                'screens.screen_provider_id',
                'screen_providers.provider_name',
                'cities.name as city_name',
                'countries.name as country_name',
                DB::raw('COUNT(DISTINCT booking_screens.booking_id) as campaign_count'),
                DB::raw('SUM(booking_screens.gross_revenue) as gross_revenue'),
                DB::raw('SUM(booking_screens.platform_share) as platform_revenue'),
                DB::raw('SUM(booking_screens.provider_share) as provider_revenue'),
                DB::raw('COALESCE(AVG(booking_screens.gross_revenue), 0) as avg_revenue')
            )
            ->groupBy('screens.id', 'screens.name', 'screens.screen_code', 'screens.screen_provider_id', 'screen_providers.provider_name', 'cities.name', 'countries.name')
            ->orderByDesc('provider_revenue')
            ->get()
            ->toArray();

        return [
            'summary' => [
                'total_providers' => $totalProviders,
                'total_provider_revenue' => $totalProviderRevenue,
                'total_platform_revenue' => $totalPlatformRevenue,
                'total_gross_revenue' => $totalGrossRevenue,
                'pending_settlement' => $pendingSettlement,
                'settled_amount' => $settledAmount,
                'outstanding_liability' => $outstandingLiability,
                'total_owned_screens' => $totalOwnedScreens,
            ],
            'provider_breakdown' => $providerBreakdown,
            'screen_breakdown' => $screenBreakdown,
        ];
    }
}
