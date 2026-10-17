<?php

namespace App\Services\Settlement;

use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueAnalyticsService
{
    /**
     * Get revenue overview KPI data.
     */
    public function overview(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth();
        $cityId = $filters['city_id'] ?? null;
        $screenId = $filters['screen_id'] ?? null;
        $planId = $filters['pricing_plan_id'] ?? null;

        $baseQuery = Booking::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', '!=', Booking::STATUS_CANCELLED);

        // Total revenue (final_amount)
        $totalRevenue = (float) (clone $baseQuery)->sum('final_amount');

        // Subtotal revenue (base price before discounts)
        $subtotalRevenue = (float) (clone $baseQuery)->sum('subtotal') ?: (float) (clone $baseQuery)->sum('price');

        // Total discount amount
        $totalDiscounts = (float) (clone $baseQuery)->sum('discount_amount');

        // Referral discount impact
        $referralDiscounts = (float) (clone $baseQuery)->sum('referral_discount');

        // Commission payouts
        $totalCommissions = (float) DB::table('partner_commissions')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'paid')
            ->sum('amount');

        // Booking count in range
        $bookingCount = (clone $baseQuery)->count();

        // Average campaign value
        $avgCampaignValue = $bookingCount > 0 ? round($totalRevenue / $bookingCount, 2) : 0;

        return [
            'total_revenue' => $totalRevenue,
            'subtotal_revenue' => $subtotalRevenue,
            'total_discounts' => $totalDiscounts,
            'first_booking_discounts' => (float) (clone $baseQuery)->sum('first_booking_discount_amount'),
            'first_booking_bookings' => (clone $baseQuery)->where('first_booking_discount_applied', true)->count(),
            'referral_discounts' => $referralDiscounts,
            'total_commissions' => $totalCommissions,
            'booking_count' => $bookingCount,
            'average_campaign_value' => $avgCampaignValue,
            'net_revenue' => $totalRevenue - $totalCommissions,
        ];
    }

    /**
     * Get daily revenue trend data.
     */
    public function dailyRevenue(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $query = Booking::where('status', '!=', Booking::STATUS_CANCELLED)
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $results = $query->select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(final_amount) as revenue'),
            DB::raw('COUNT(*) as bookings')
        )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Fill missing dates with zero
        $dateRange = $this->generateDateRange($dateFrom, $dateTo);
        $revenueMap = $results->keyBy('date');

        $daily = [];
        foreach ($dateRange as $dateStr) {
            $dayData = $revenueMap->get($dateStr);
            $daily[] = [
                'date' => $dateStr,
                'revenue' => (float) ($dayData->revenue ?? 0),
                'bookings' => (int) ($dayData->bookings ?? 0),
            ];
        }

        return $daily;
    }

    /**
     * Get monthly revenue trend data.
     */
    public function monthlyRevenue(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $query = Booking::where('status', '!=', Booking::STATUS_CANCELLED)
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $monthExpr = $isSqlite ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";

        $results = $query->select(
            DB::raw("{$monthExpr} as month"),
            DB::raw('SUM(final_amount) as revenue'),
            DB::raw('COUNT(*) as bookings'),
            DB::raw('AVG(final_amount) as avg_value')
        )
            ->groupBy(DB::raw($monthExpr))
            ->orderBy('month')
            ->get();

        return $results->map(function ($row) {
            return [
                'month' => $row->month,
                'revenue' => (float) $row->revenue,
                'bookings' => (int) $row->bookings,
                'avg_value' => round((float) $row->avg_value, 2),
            ];
        })->toArray();
    }

    /**
     * Get screen-wise revenue breakdown.
     */
    public function screenWiseRevenue(string $dateFrom, string $dateTo, array $filters = []): array
    {
        $singleScreenRev = Booking::whereNotNull('screen_id')
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                'screen_id',
                DB::raw('SUM(final_amount) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('screen_id')
            ->with('screen:id,name,screen_code')
            ->get();

        // Campaign revenue - via booking_screens pivot
        $campaignRev = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->whereNotIn('bookings.status', [Booking::STATUS_CANCELLED, Booking::STATUS_PAYMENT_PENDING])
            ->select(
                'booking_screens.screen_id',
                DB::raw('SUM(bookings.final_amount / bookings.total_screens) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('booking_screens.screen_id')
            ->get();

        $merged = [];
        foreach ($singleScreenRev as $row) {
            $sid = $row->screen_id;
            $merged[$sid] = [
                'screen_id' => $sid,
                'screen_name' => $row->screen?->name ?? "Screen #{$sid}",
                'revenue' => (float) $row->revenue,
                'bookings' => (int) $row->bookings,
            ];
        }
        foreach ($campaignRev as $row) {
            $sid = $row->screen_id;
            if (isset($merged[$sid])) {
                $merged[$sid]['revenue'] += (float) $row->revenue;
                $merged[$sid]['bookings'] += (int) $row->bookings;
            } else {
                $merged[$sid] = [
                    'screen_id' => $sid,
                    'screen_name' => "Screen #{$sid}",
                    'revenue' => (float) $row->revenue,
                    'bookings' => (int) $row->bookings,
                ];
            }
        }

        usort($merged, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_values($merged);
    }

    /**
     * Get city-wise revenue breakdown.
     */
    public function cityWiseRevenue(string $dateFrom, string $dateTo, array $filters = []): array
    {
        // Single-screen bookings
        $singleScreen = Booking::whereNotNull('bookings.screen_id')
            ->join('screens', 'bookings.screen_id', '=', 'screens.id')
            ->join('cities', 'screens.city_id', '=', 'cities.id')
            ->where('bookings.status', '!=', Booking::STATUS_CANCELLED)
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->select(
                'cities.id as city_id',
                'cities.name as city_name',
                DB::raw('SUM(bookings.final_amount) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('cities.id', 'cities.name')
            ->get();

        // Campaign bookings via booking_screens pivot
        $campaignRev = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('screens', 'booking_screens.screen_id', '=', 'screens.id')
            ->join('cities', 'screens.city_id', '=', 'cities.id')
            ->whereNotIn('bookings.status', [Booking::STATUS_CANCELLED, Booking::STATUS_PAYMENT_PENDING])
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->select(
                'cities.id as city_id',
                'cities.name as city_name',
                DB::raw('SUM(bookings.final_amount / NULLIF(bookings.total_screens, 0)) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('cities.id', 'cities.name')
            ->get();

        $merged = [];
        foreach ($singleScreen as $row) {
            $cid = $row->city_id;
            $merged[$cid] = [
                'city_id' => $cid,
                'city_name' => $row->city_name,
                'revenue' => (float) $row->revenue,
                'bookings' => (int) $row->bookings,
            ];
        }
        foreach ($campaignRev as $row) {
            $cid = $row->city_id;
            if (isset($merged[$cid])) {
                $merged[$cid]['revenue'] += (float) $row->revenue;
                $merged[$cid]['bookings'] += (int) $row->bookings;
            } else {
                $merged[$cid] = [
                    'city_id' => $cid,
                    'city_name' => $row->city_name,
                    'revenue' => (float) $row->revenue,
                    'bookings' => (int) $row->bookings,
                ];
            }
        }

        usort($merged, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return array_values($merged);
    }

    /**
     * Get referral discount impact data.
     */
    public function referralImpact(string $dateFrom, string $dateTo): array
    {
        $usageData = DB::table('referral_usages')
            ->join('referral_codes', 'referral_usages.referral_code_id', '=', 'referral_codes.id')
            ->join('partners', 'referral_codes.partner_id', '=', 'partners.id')
            ->whereBetween('referral_usages.created_at', [$dateFrom, $dateTo])
            ->select(
                'partners.id as partner_id',
                'partners.name as partner_name',
                'referral_codes.code',
                DB::raw('SUM(referral_usages.discount_amount) as total_discount'),
                DB::raw('SUM(referral_usages.commission_amount) as total_commission'),
                DB::raw('COUNT(*) as usage_count')
            )
            ->groupBy('partners.id', 'partners.name', 'referral_codes.code')
            ->orderByDesc('total_discount')
            ->get();

        return $usageData->toArray();
    }

    /**
     * Generate a range of date strings.
     */
    private function generateDateRange(string $start, string $end): array
    {
        $dates = [];
        $current = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        while ($current->lte($endDate)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $dates;
    }
}
