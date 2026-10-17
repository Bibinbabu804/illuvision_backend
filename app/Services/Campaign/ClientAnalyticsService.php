<?php

namespace App\Services\Campaign;

use App\Models\Booking;
use App\Models\User;
use App\Models\ReferralUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientAnalyticsService
{
    /**
     * Get aggregated client KPI data.
     */
    public function kpis(): array
    {
        $totalClients = User::count();
        $activeClients = User::whereHas('bookings', function ($q) {
            $q->whereIn('status', [
                Booking::STATUS_APPROVED,
                Booking::STATUS_SCHEDULED,
                Booking::STATUS_RUNNING,
            ]);
        })->count();

        $totalCampaigns = Booking::count();
        $totalRevenue = (float) Booking::where('status', '!=', Booking::STATUS_CANCELLED)
            ->sum('final_amount');

        $referralClients = ReferralUsage::distinct('user_id')->count('user_id');

        $pendingCampaigns = Booking::whereIn('status', [
            Booking::STATUS_PAYMENT_PENDING,
            Booking::STATUS_MEDIA_REVIEW_PENDING,
            Booking::STATUS_PENDING_APPROVAL,
        ])->count();

        return [
            'total_clients' => $totalClients,
            'active_clients' => $activeClients,
            'total_campaigns' => $totalCampaigns,
            'total_revenue' => $totalRevenue,
            'referral_clients' => $referralClients,
            'pending_campaigns' => $pendingCampaigns,
        ];
    }

    /**
     * Get campaign analytics for a specific client.
     */
    public function clientCampaignAnalytics(int $userId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $query = Booking::where('user_id', $userId);

        if ($dateFrom) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom));
        }
        if ($dateTo) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $totalCampaigns = (clone $query)->count();
        $activeCampaigns = (clone $query)->whereIn('status', [
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
        ])->count();
        $completedCampaigns = (clone $query)->where('status', Booking::STATUS_COMPLETED)->count();
        $cancelledCampaigns = (clone $query)->where('status', Booking::STATUS_CANCELLED)->count();

        $totalSpend = (float) (clone $query)->where('status', '!=', Booking::STATUS_CANCELLED)
            ->sum('final_amount');

        $avgCampaignValue = $totalCampaigns > 0 ? round($totalSpend / $totalCampaigns, 2) : 0;

        // Most booked cities
        $topCities = DB::table('bookings')
            ->join('booking_screens', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('cities', 'booking_screens.city_id', '=', 'cities.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', '!=', Booking::STATUS_PAYMENT_PENDING)
            ->select('cities.id', 'cities.name', DB::raw('COUNT(*) as total'))
            ->groupBy('cities.id', 'cities.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->toArray();

        // Most booked screens
        $topScreens = DB::table('bookings')
            ->join('booking_screens', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('screens', 'booking_screens.screen_id', '=', 'screens.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', '!=', Booking::STATUS_PAYMENT_PENDING)
            ->select('screens.id', 'screens.name', DB::raw('COUNT(*) as total'))
            ->groupBy('screens.id', 'screens.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->toArray();

        // Spending trend (last 6 months)
        $sixMonthsAgo = Carbon::now()->subMonths(6)->startOfMonth();
        $spendingTrend = Booking::where('user_id', $userId)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('SUM(final_amount) as spend'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->toArray();

        // Campaign activity over time (last 12 months)
        $twelveMonthsAgo = Carbon::now()->subMonths(12)->startOfMonth();
        $campaignActivity = Booking::where('user_id', $userId)
            ->where('created_at', '>=', $twelveMonthsAgo)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status IN ('approved','scheduled','running') THEN 1 ELSE 0 END) as active")
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->toArray();

        return [
            'total_campaigns' => $totalCampaigns,
            'active_campaigns' => $activeCampaigns,
            'completed_campaigns' => $completedCampaigns,
            'cancelled_campaigns' => $cancelledCampaigns,
            'total_spend' => $totalSpend,
            'average_campaign_value' => $avgCampaignValue,
            'top_cities' => $topCities,
            'top_screens' => $topScreens,
            'spending_trend' => $spendingTrend,
            'campaign_activity' => $campaignActivity,
        ];
    }

    /**
     * Get referral information for a specific client.
     */
    public function clientReferralInfo(int $userId): array
    {
        $usage = ReferralUsage::where('user_id', $userId)
            ->with('referralCode.partner')
            ->latest()
            ->first();

        if (!$usage) {
            return [
                'has_referral' => false,
                'referred_by' => null,
                'referral_code' => null,
                'referral_savings' => 0,
                'generated_commission' => 0,
                'usage_history' => [],
            ];
        }

        $totalSavings = (float) ReferralUsage::where('user_id', $userId)
            ->sum('discount_amount');

        $totalCommission = (float) ReferralUsage::where('user_id', $userId)
            ->sum('commission_amount');

        $usageHistory = ReferralUsage::where('user_id', $userId)
            ->with('referralCode.partner')
            ->latest()
            ->get()
            ->toArray();

        return [
            'has_referral' => true,
            'referred_by' => $usage->referralCode?->partner ? [
                'id' => $usage->referralCode->partner->id,
                'name' => $usage->referralCode->partner->name,
                'contact_person' => $usage->referralCode->partner->contact_person,
                'email' => $usage->referralCode->partner->email,
                'phone' => $usage->referralCode->partner->phone,
            ] : null,
            'referral_code' => $usage->referralCode?->code,
            'referral_savings' => $totalSavings,
            'generated_commission' => $totalCommission,
            'usage_history' => $usageHistory,
        ];
    }
}
