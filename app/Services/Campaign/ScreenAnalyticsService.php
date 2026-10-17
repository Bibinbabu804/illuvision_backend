<?php

namespace App\Services\Campaign;

use App\Models\Booking;
use App\Models\Screen;
use App\Support\Configs\BookingConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScreenAnalyticsService
{
    protected BookingConfig $bookingConfig;

    public function __construct(BookingConfig $bookingConfig)
    {
        $this->bookingConfig = $bookingConfig;
    }

    /**
     * Get screen performance overview.
     */
    public function overview(array $filters = []): array
    {
        $maxAds = $this->bookingConfig->maxAdsPerDay();
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth();

        $totalScreens = Screen::where('status', 'active')->count();
        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];

        // Screens with at least one active booking
        $activeScreenIds = Booking::whereBetween('start_time', [$dateFrom, $dateTo])
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('screen_id')
            ->distinct('screen_id')
            ->pluck('screen_id');

        $campaignScreenIds = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereBetween('bookings.start_time', [$dateFrom, $dateTo])
            ->whereIn('bookings.status', $activeStatuses)
            ->distinct('booking_screens.screen_id')
            ->pluck('booking_screens.screen_id');

        $allActiveScreenIds = $activeScreenIds->merge($campaignScreenIds)->unique()->count();

        // Occupancy rate
        $totalSlots = $totalScreens * $maxAds;
        $activeSingleCount = Booking::whereBetween('start_time', [$dateFrom, $dateTo])
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('screen_id')
            ->count();

        $campaignCount = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereBetween('bookings.start_time', [$dateFrom, $dateTo])
            ->whereIn('bookings.status', $activeStatuses)
            ->count();

        $totalActive = $activeSingleCount + $campaignCount;
        $occupancyRate = $totalSlots > 0 ? round(($totalActive / $totalSlots) * 100, 1) : 0;

        return [
            'total_screens' => $totalScreens,
            'active_screens' => $allActiveScreenIds,
            'inactive_screens' => $totalScreens - $allActiveScreenIds,
            'total_active_bookings' => $totalActive,
            'occupancy_rate' => $occupancyRate,
            'max_capacity' => $totalSlots,
            'max_ads_per_day' => $maxAds,
        ];
    }

    /**
     * Get ranked screen performance data.
     */
    public function screenPerformance(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $maxAds = $this->bookingConfig->maxAdsPerDay();
        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];
        $completedStatuses = ['completed', 'running'];

        $statusFilter = $filters['status'] ?? null;
        $screensQuery = Screen::with('city:id,name');

        if ($statusFilter) {
            if ($statusFilter === 'available') {
                $screensQuery->where('status', 'active')->where('booking_status', 'available');
            } elseif ($statusFilter === 'sold_out') {
                $screensQuery->where('booking_status', 'sold_out');
            } elseif ($statusFilter === 'maintenance') {
                $screensQuery->where('status', 'maintenance');
            } else {
                $screensQuery->where('status', $statusFilter);
            }
        } else {
            $screensQuery->where('status', 'active');
        }

        $screens = $screensQuery->get()->keyBy('id');

        $singleCounts = Booking::whereNotNull('screen_id')
            ->whereBetween('start_time', [$dateFrom, $dateTo])
            ->whereIn('status', $activeStatuses)
            ->select('screen_id', DB::raw('COUNT(*) as count'))
            ->groupBy('screen_id')
            ->pluck('count', 'screen_id');

        $campaignCounts = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereBetween('bookings.start_time', [$dateFrom, $dateTo])
            ->whereIn('bookings.status', $activeStatuses)
            ->select('booking_screens.screen_id', DB::raw('COUNT(*) as count'))
            ->groupBy('booking_screens.screen_id')
            ->pluck('count', 'screen_id');

        $singleRevenue = Booking::whereNotNull('screen_id')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->select('screen_id', DB::raw('SUM(final_amount) as revenue'))
            ->groupBy('screen_id')
            ->pluck('revenue', 'screen_id');

        $campaignRevenue = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->where('bookings.status', '!=', Booking::STATUS_CANCELLED)
            ->select(
                'booking_screens.screen_id',
                DB::raw('SUM(bookings.final_amount / NULLIF(bookings.total_screens, 0)) as revenue')
            )
            ->groupBy('booking_screens.screen_id')
            ->pluck('revenue', 'screen_id');

        $completedCounts = Booking::whereNotNull('screen_id')
            ->whereBetween('start_time', [$dateFrom, $dateTo])
            ->whereIn('status', $completedStatuses)
            ->select('screen_id', DB::raw('COUNT(*) as completed'))
            ->groupBy('screen_id')
            ->pluck('completed', 'screen_id');

        $results = [];
        foreach ($screens as $sid => $screen) {
            $totalBookings = (int) ($singleCounts[$sid] ?? 0) + (int) ($campaignCounts[$sid] ?? 0);
            $revenue = (float) ($singleRevenue[$sid] ?? 0) + (float) ($campaignRevenue[$sid] ?? 0);
            $completed = (int) ($completedCounts[$sid] ?? 0);
            $occupancyPct = min(100, round(($totalBookings / $maxAds) * 100, 1));

            $results[] = [
                'screen_id' => $sid,
                'screen_name' => $screen->name,
                'screen_code' => $screen->screen_code,
                'screen_type' => $screen->screen_type,
                'city_name' => $screen->city?->name ?? 'Unknown',
                'occupancy_pct' => $occupancyPct,
                'booking_count' => $totalBookings,
                'revenue' => $revenue,
                'completed_bookings' => $completed,
                'status' => $screen->status,
            ];
        }

        usort($results, fn($a, $b) => $b['booking_count'] <=> $a['booking_count']);

        return $results;
    }

    /**
     * Get city-level screen analytics.
     */
    public function cityPerformance(string $dateFrom, string $dateTo): array
    {
        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];

        $results = DB::table('screens')
            ->join('cities', 'screens.city_id', '=', 'cities.id')
            ->leftJoin('bookings', function ($join) use ($dateFrom, $dateTo, $activeStatuses) {
                $join->on('screens.id', '=', 'bookings.screen_id')
                    ->whereBetween('bookings.start_time', [$dateFrom, $dateTo])
                    ->whereIn('bookings.status', $activeStatuses);
            })
            ->select(
                'cities.id as city_id',
                'cities.name as city_name',
                DB::raw('COUNT(DISTINCT screens.id) as screen_count'),
                DB::raw('COUNT(bookings.id) as booking_count')
            )
            ->where('screens.status', 'active')
            ->groupBy('cities.id', 'cities.name')
            ->get();

        $campaignData = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('screens', 'booking_screens.screen_id', '=', 'screens.id')
            ->join('cities', 'screens.city_id', '=', 'cities.id')
            ->whereBetween('bookings.start_time', [$dateFrom, $dateTo])
            ->whereIn('bookings.status', $activeStatuses)
            ->select(
                'cities.id as city_id',
                DB::raw('COUNT(*) as campaign_booking_count'),
                DB::raw('SUM(bookings.final_amount / NULLIF(bookings.total_screens, 0)) as revenue')
            )
            ->groupBy('cities.id')
            ->get()
            ->keyBy('city_id');

        $revenueData = Booking::whereNotNull('bookings.screen_id')
            ->join('screens', 'bookings.screen_id', '=', 'screens.id')
            ->join('cities', 'screens.city_id', '=', 'cities.id')
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->where('bookings.status', '!=', Booking::STATUS_CANCELLED)
            ->select(
                'cities.id as city_id',
                DB::raw('SUM(bookings.final_amount) as revenue')
            )
            ->groupBy('cities.id')
            ->pluck('revenue', 'city_id');

        $cityResults = [];
        foreach ($results as $row) {
            $cid = $row->city_id;
            $campaign = $campaignData->get($cid);
            $totalRevenue = (float) ($revenueData[$cid] ?? 0) + (float) ($campaign->revenue ?? 0);
            $totalBookings = (int) $row->booking_count + (int) ($campaign->campaign_booking_count ?? 0);

            $cityResults[] = [
                'city_id' => $cid,
                'city_name' => $row->city_name,
                'screen_count' => (int) $row->screen_count,
                'booking_count' => $totalBookings,
                'revenue' => round($totalRevenue, 2),
                'avg_revenue_per_screen' => $row->screen_count > 0
                    ? round($totalRevenue / $row->screen_count, 2)
                    : 0,
            ];
        }

        usort($cityResults, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $cityResults;
    }

    /**
     * Get campaign-specific analytics.
     */
    public function campaignAnalytics(string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        $campaigns = Booking::with(['pricingPlan:id,name', 'bookingScreens.screen:id,name'])
            ->where(function ($q) {
                $q->whereHas('bookingScreens')
                  ->orWhere('total_screens', '>', 1);
            })
            ->whereBetween('created_at', [$from, $to])
            ->select(
                'id',
                'campaign_title',
                'title',
                'advertiser_name',
                'start_time',
                'end_time',
                'status',
                'final_amount',
                'total_screens',
                'source',
                'created_at'
            )
            ->orderByDesc('created_at')
            ->get()
            ->take(50);

        return $campaigns->map(function ($c) {
            $start = Carbon::parse($c->start_time);
            $end = Carbon::parse($c->end_time);
            $durationMinutes = \App\Support\DurationFormatter::calculateMinutes($start, $end);
            $durationDays = $durationMinutes >= 1440 ? (int) round($durationMinutes / 1440) : round($durationMinutes / 1440, 4);
            $durationLabel = \App\Support\DurationFormatter::formatMinutes($durationMinutes);

            return [
                'id' => $c->id,
                'title' => $c->campaign_title ?: $c->title,
                'advertiser_name' => $c->advertiser_name,
                'start_time' => $c->start_time,
                'end_time' => $c->end_time,
                'duration_minutes' => $durationMinutes,
                'duration_days' => $durationDays,
                'duration_label' => $durationLabel,
                'status' => $c->status,
                'final_amount' => (float) $c->final_amount,
                'total_screens' => (int) $c->total_screens,
                'sources' => $c->source,
                'pricing_plan' => $c->pricingPlan?->name,
                'screens' => $c->bookingScreens->pluck('screen.name')->filter()->values(),
                'created_at' => $c->created_at,
            ];
        })->toArray();
    }

    /**
     * Get screen utilization distribution (for heatmap/charting).
     */
    public function utilizationDistribution(string $dateFrom, string $dateTo): array
    {
        $maxAds = $this->bookingConfig->maxAdsPerDay();
        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];
        $screens = Screen::where('status', 'active')->count();

        $daily = [];
        $current = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($current->lte($end)) {
            $dayStr = $current->format('Y-m-d');

            $singleCount = Booking::whereNotNull('screen_id')
                ->whereDate('start_time', '<=', $dayStr)
                ->whereDate('end_time', '>=', $dayStr)
                ->whereIn('status', $activeStatuses)
                ->count();

            $campaignCount = DB::table('booking_screens')
                ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
                ->whereDate('bookings.start_time', '<=', $dayStr)
                ->whereDate('bookings.end_time', '>=', $dayStr)
                ->whereIn('bookings.status', $activeStatuses)
                ->count();

            $totalActive = $singleCount + $campaignCount;
            $maxSlots = $screens * $maxAds;
            $utilization = $maxSlots > 0 ? round(($totalActive / $maxSlots) * 100, 1) : 0;

            $daily[] = [
                'date' => $dayStr,
                'utilization' => $utilization,
                'active_bookings' => $totalActive,
                'max_capacity' => $maxSlots,
                'max_ads_per_day' => $maxAds,
            ];

            $current->addDay();
        }

        return $daily;
    }
}
