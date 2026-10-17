<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReferralUsage;
use App\Models\PartnerCommission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentAnalyticsService
{
    /**
     * Get payment KPIs for the dashboard.
     */
    public function getKPIs(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfDay()->format('Y-m-d H:i:s');

        // Total Revenue (from successful payments)
        $totalRevenue = Payment::successful()
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->sum('amount');

        // Today's Revenue
        $todayRevenue = Payment::successful()
            ->whereDate('paid_at', Carbon::today())
            ->sum('amount');

        // Pending Payments
        $pendingCount = Payment::pending()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        // Failed Payments
        $failedCount = Payment::failed()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        // Total Refund Amount
        $totalRefunds = Refund::processed()
            ->whereBetween('processed_at', [$dateFrom, $dateTo])
            ->sum('amount');

        // Referral Revenue (discounts applied via referral codes)
        $referralRevenue = ReferralUsage::whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('discount_amount');

        // Partner Commissions Generated
        $commissionsGenerated = PartnerCommission::whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('amount');

        // Total Successful Payments Count
        $successfulCount = Payment::successful()
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->count();

        // Pending Refunds Count
        $pendingRefundsCount = Refund::pending()->count();

        return [
            'total_revenue' => (float) $totalRevenue,
            'today_revenue' => (float) $todayRevenue,
            'pending_payments' => $pendingCount,
            'failed_payments' => $failedCount,
            'successful_payments' => $successfulCount,
            'total_refund_amount' => (float) $totalRefunds,
            'referral_revenue' => (float) $referralRevenue,
            'commissions_generated' => (float) $commissionsGenerated,
            'pending_refunds' => $pendingRefundsCount,
        ];
    }

    /**
     * Get revenue trends over time (daily/monthly).
     */
    public function revenueTrends(string $dateFrom, string $dateTo, string $groupBy = 'day'): array
    {
        $dateExpr = $groupBy === 'month'
            ? DB::raw("DATE_FORMAT(paid_at, '%Y-%m') as period")
            : DB::raw('DATE(paid_at) as period');

        $results = Payment::successful()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->select(
                $dateExpr,
                DB::raw('SUM(amount) as revenue'),
                DB::raw('COUNT(*) as transactions'),
                DB::raw('AVG(amount) as average_transaction')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        // Fill gaps
        $trends = [];
        $current = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        $interval = $groupBy === 'month' ? 'addMonth' : 'addDay';
        $format = $groupBy === 'month' ? 'Y-m' : 'Y-m-d';

        while ($current->lte($end)) {
            $period = $current->format($format);
            $dayData = $results->get($period);
            $trends[] = [
                'date' => $period,
                'revenue' => (float) ($dayData->revenue ?? 0),
                'transactions' => (int) ($dayData->transactions ?? 0),
                'average_transaction' => (float) ($dayData->average_transaction ?? 0),
            ];
            $current->{$interval}();
        }

        return $trends;
    }

    /**
     * Get payment status distribution.
     */
    public function paymentStatusDistribution(array $filters = []): array
    {
        $query = Payment::query();

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $distribution = $query->select('payment_status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('payment_status')
            ->get()
            ->keyBy('payment_status');

        return [
            'pending' => [
                'count' => (int) ($distribution->get('pending')->count ?? 0),
                'amount' => (float) ($distribution->get('pending')->total_amount ?? 0),
            ],
            'verification_pending' => [
                'count' => (int) ($distribution->get('verification_pending')->count ?? 0),
                'amount' => (float) ($distribution->get('verification_pending')->total_amount ?? 0),
            ],
            'paid' => [
                'count' => (int) ($distribution->get('paid')->count ?? 0),
                'amount' => (float) ($distribution->get('paid')->total_amount ?? 0),
            ],
            'failed' => [
                'count' => (int) ($distribution->get('failed')->count ?? 0),
                'amount' => (float) ($distribution->get('failed')->total_amount ?? 0),
            ],
            'refunded' => [
                'count' => (int) ($distribution->get('refunded')->count ?? 0),
                'amount' => (float) ($distribution->get('refunded')->total_amount ?? 0),
            ],
            'partially_refunded' => [
                'count' => (int) ($distribution->get('partially_refunded')->count ?? 0),
                'amount' => (float) ($distribution->get('partially_refunded')->total_amount ?? 0),
            ],
        ];
    }

    /**
     * Get payment method usage breakdown.
     */
    public function paymentMethodUsage(array $filters = []): array
    {
        $query = Payment::successful();

        if (!empty($filters['date_from'])) {
            $query->whereDate('paid_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('paid_at', '<=', $filters['date_to']);
        }

        return $query->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->get()
            ->toArray();
    }

    /**
     * Get refund trends.
     */
    public function refundTrends(string $dateFrom, string $dateTo): array
    {
        $results = Refund::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw("SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed_count"),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trends = [];
        $current = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $dayData = $results->get($dateStr);
            $trends[] = [
                'date' => $dateStr,
                'count' => (int) ($dayData->count ?? 0),
                'total_amount' => (float) ($dayData->total_amount ?? 0),
                'processed' => (int) ($dayData->processed_count ?? 0),
                'pending' => (int) ($dayData->pending_count ?? 0),
                'failed' => (int) ($dayData->failed_count ?? 0),
            ];
            $current->addDay();
        }

        return $trends;
    }

    /**
     * Get comprehensive analytics overview for the analytics page.
     */
    public function getAnalytics(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfDay()->format('Y-m-d H:i:s');

        return [
            'kpis' => $this->getKPIs($filters),
            'revenue_trends' => $this->revenueTrends($dateFrom, $dateTo),
            'status_distribution' => $this->paymentStatusDistribution($filters),
            'payment_methods' => $this->paymentMethodUsage($filters),
            'refund_trends' => $this->refundTrends($dateFrom, $dateTo),
        ];
    }
}
