<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Models\Booking;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rewards and loyalty programme analytics report.
 *
 * Lifetime KPIs (total issued/redeemed/expired, outstanding liability, account
 * counts) are intentionally NOT date filtered, matching the documented metric
 * definition in phase-0.11-metric-definition-map.json. Only the `period_*`
 * figures and the bookings-with-rewards block are bounded by the report period.
 */
class RewardReportQuery implements ReportQuery
{
    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        // ── Summary KPIs (lifetime, not date filtered) ──
        $totalIssued = (float) RewardAccount::sum('lifetime_earned');
        $totalRedeemed = (float) RewardAccount::sum('lifetime_redeemed');
        $totalExpired = (float) RewardAccount::sum('lifetime_expired');
        $outstandingLiability = (float) RewardAccount::sum('balance');
        $totalAccounts = RewardAccount::count();
        $accountsWithBalance = RewardAccount::where('balance', '>', 0)->count();

        // ── Period KPIs ──
        $periodIssued = (float) RewardTransaction::whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('type', ['earn', 'admin_bonus'])
            ->where('points', '>', 0)
            ->sum('points');

        $periodRedeemed = (float) RewardTransaction::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('type', 'redeem')
            ->sum(DB::raw('ABS(points)'));

        $periodExpired = (float) RewardTransaction::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('type', 'expire')
            ->sum(DB::raw('ABS(points)'));

        // ── Booking with rewards ──
        $bookingsWithRewards = Booking::where('reward_discount_amount', '>', 0)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();
        $totalRevenueInfluenced = (float) Booking::where('reward_discount_amount', '>', 0)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('final_amount');
        $totalDiscountGiven = (float) Booking::where('reward_discount_amount', '>', 0)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('reward_discount_amount');

        // ── Redemption Rate ──
        $redemptionRate = $totalIssued > 0
            ? round(($totalRedeemed / $totalIssued) * 100, 2)
            : 0;

        // ── Top Customers by points earned ──
        $topCustomers = RewardAccount::with('user:id,name,email')
            ->orderByDesc('lifetime_earned')
            ->take(10)
            ->get()
            ->map(function ($account) {
                return [
                    'user_id' => $account->user_id,
                    'name' => $account->user?->name ?? 'Unknown',
                    'email' => $account->user?->email ?? '',
                    'balance' => $account->balance,
                    'lifetime_earned' => $account->lifetime_earned,
                    'lifetime_redeemed' => $account->lifetime_redeemed,
                    'lifetime_expired' => $account->lifetime_expired,
                ];
            });

        // ── Monthly trend (rolling 12 months, independent of the report period) ──
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $monthExpr = $isSqlite ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthlyTrend = RewardTransaction::where('created_at', '>=', Carbon::now()->subMonths(12)->startOfMonth())
            ->select(
                DB::raw("{$monthExpr} as month"),
                DB::raw("SUM(CASE WHEN type IN ('earn','admin_bonus') AND points > 0 THEN points ELSE 0 END) as issued"),
                DB::raw("SUM(CASE WHEN type = 'redeem' THEN ABS(points) ELSE 0 END) as redeemed"),
                DB::raw("SUM(CASE WHEN type = 'expire' THEN ABS(points) ELSE 0 END) as expired")
            )
            ->groupBy(DB::raw($monthExpr))
            ->orderBy('month')
            ->get()
            ->toArray();

        return [
            'kpis' => [
                'total_issued' => $totalIssued,
                'total_redeemed' => $totalRedeemed,
                'total_expired' => $totalExpired,
                'outstanding_liability' => $outstandingLiability,
                'total_accounts' => $totalAccounts,
                'accounts_with_balance' => $accountsWithBalance,
                'redemption_rate' => $redemptionRate,
                'period_issued' => $periodIssued,
                'period_redeemed' => $periodRedeemed,
                'period_expired' => $periodExpired,
                'bookings_with_rewards' => $bookingsWithRewards,
                'total_revenue_influenced' => $totalRevenueInfluenced,
                'total_discount_given' => $totalDiscountGiven,
                'average_reward_per_booking' => $bookingsWithRewards > 0
                    ? round($totalDiscountGiven / $bookingsWithRewards, 2)
                    : 0,
            ],
            'top_customers' => $topCustomers,
            'monthly_trend' => $monthlyTrend,
        ];
    }
}
