<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Referral and partner analytics report.
 *
 * Reads the persisted referral attribution and partner commission ledgers
 * directly. Commission amounts are summed from the `partner_commissions` table
 * as persisted by the financial domain — they are never recomputed here.
 */
class ReferralReportQuery implements ReportQuery
{
    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        // Referral revenue / discounts
        $referralRevenue = (float) DB::table('referral_usages')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('discount_amount');

        $totalCommissions = (float) DB::table('partner_commissions')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'paid')
            ->sum('amount');

        $pendingCommissions = (float) DB::table('partner_commissions')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'pending')
            ->sum('amount');

        // Total referral usage count
        $usageCount = DB::table('referral_usages')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        // Top partners by commission
        $topPartners = DB::table('partners')
            ->join('referral_codes', 'partners.id', '=', 'referral_codes.partner_id')
            ->join('referral_usages', 'referral_codes.id', '=', 'referral_usages.referral_code_id')
            ->whereBetween('referral_usages.created_at', [$dateFrom, $dateTo])
            ->select(
                'partners.id',
                'partners.name',
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('SUM(referral_usages.discount_amount) as total_discount'),
                DB::raw('SUM(referral_usages.commission_amount) as total_commission')
            )
            ->groupBy('partners.id', 'partners.name')
            ->orderByDesc('total_commission')
            ->get();

        // Top referral codes by usage
        $topCodes = DB::table('referral_codes')
            ->join('partners', 'referral_codes.partner_id', '=', 'partners.id')
            ->leftJoin('referral_usages', 'referral_codes.id', '=', 'referral_usages.referral_code_id')
            ->whereBetween('referral_usages.created_at', [$dateFrom, $dateTo])
            ->select(
                'referral_codes.id',
                'referral_codes.code',
                'partners.name as partner_name',
                DB::raw('COUNT(referral_usages.id) as usage_count'),
                DB::raw('SUM(referral_usages.discount_amount) as total_discount'),
                DB::raw('SUM(referral_usages.commission_amount) as total_commission')
            )
            ->groupBy('referral_codes.id', 'referral_codes.code', 'partners.name')
            ->orderByDesc('usage_count')
            ->get()
            ->toArray();

        // Monthly referral trend
        $monthlyTrend = DB::table('referral_usages')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('SUM(discount_amount) as total_discount'),
                DB::raw('SUM(commission_amount) as total_commission')
            )
            ->groupBy(DB::raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->toArray();

        return [
            'kpis' => [
                'referral_revenue' => $referralRevenue,
                'total_commissions' => $totalCommissions,
                'pending_commissions' => $pendingCommissions,
                'total_usage_count' => $usageCount,
                'top_partner' => $topPartners->first()->name ?? 'N/A',
                'active_referral_campaigns' => DB::table('referral_codes')->where('status', 'active')->count(),
            ],
            'top_partners' => $topPartners,
            'top_codes' => $topCodes,
            'monthly_trend' => $monthlyTrend,
        ];
    }
}
