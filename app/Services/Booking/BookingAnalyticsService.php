<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingAnalyticsService
{
    /**
     * Get booking analytics overview.
     */
    public function overview(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth();

        $query = Booking::whereBetween('created_at', [$dateFrom, $dateTo]);

        $total = (clone $query)->count();
        \Log::info([$query->toSql(), $query->getBindings()]);

        $statusCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $campaignCount = (clone $query)
            ->where(function ($q) {
                $q->whereHas('bookingScreens')
                    ->orWhere('total_screens', '>', 1);
            })
            ->count();

        $pendingApproval = (int) $statusCounts->get(Booking::STATUS_PENDING_APPROVAL, 0);
        $approved = (int) $statusCounts->get(Booking::STATUS_APPROVED, 0);
        $running = (int) $statusCounts->get(Booking::STATUS_RUNNING, 0);
        $completed = (int) $statusCounts->get(Booking::STATUS_COMPLETED, 0);
        $cancelled = (int) $statusCounts->get(Booking::STATUS_CANCELLED, 0);
        $rejected = (int) $statusCounts->get(Booking::STATUS_REJECTED, 0);
        $mediaReview = (int) $statusCounts->get(Booking::STATUS_MEDIA_REVIEW_PENDING, 0);

        $totalValue = (float) (clone $query)->where('status', '!=', Booking::STATUS_CANCELLED)->sum('final_amount');
        $avgValue = $total > 0 ? round($totalValue / $total, 2) : 0;

        return [
            'total_bookings' => $total,
            'campaign_bookings' => $campaignCount,
            'single_bookings' => $total - $campaignCount,
            'status_breakdown' => [
                'payment_pending' => (int) $statusCounts->get(Booking::STATUS_PAYMENT_PENDING, 0),
                'media_review_pending' => $mediaReview,
                'pending_approval' => $pendingApproval,
                'approved' => $approved,
                'rejected' => $rejected,
                'scheduled' => (int) $statusCounts->get(Booking::STATUS_SCHEDULED, 0),
                'running' => $running,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'draft' => (int) $statusCounts->get(Booking::STATUS_DRAFT, 0),
            ],
            'pending_review' => $mediaReview + $pendingApproval,
            'active_campaigns' => $approved + $running,
            'total_value' => $totalValue,
            'average_campaign_value' => $avgValue,
            'approval_rate' => $total > 0 ? round((($approved + $running + $completed) / $total) * 100, 1) : 0,
            'cancellation_rate' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get booking trends over time.
     */
    public function bookingTrends(string $dateFrom, string $dateTo, string $groupBy = 'day'): array
    {
        $dateExpr = $groupBy === 'month'
            ? DB::raw("DATE_FORMAT(created_at, '%Y-%m') as period")
            : DB::raw('DATE(created_at) as period');

        $results = Booking::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                $dateExpr,
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status NOT IN ('cancelled', 'rejected') THEN 1 ELSE 0 END) as successful"),
                DB::raw('SUM(final_amount) as revenue')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $results->toArray();
    }

    /**
     * Get campaign approval performance metrics.
     */
    public function approvalMetrics(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? Carbon::now()->startOfMonth();
        $dateTo = $filters['date_to'] ?? Carbon::now()->endOfMonth();

        $approved = Booking::whereBetween('approved_at', [$dateFrom, $dateTo])
            ->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED])
            ->count();

        $rejected = Booking::whereBetween('approved_at', [$dateFrom, $dateTo])
            ->where('status', Booking::STATUS_REJECTED)
            ->count();

        $total = $approved + $rejected;

        // Average approval time (in hours)
        $avgApprovalTime = Booking::whereNotNull('approved_at')
            ->whereBetween('approved_at', [$dateFrom, $dateTo])
            ->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED])
            ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, created_at, approved_at)) as avg_hours'))
            ->value('avg_hours');

        return [
            'total_decided' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            'average_approval_time_hours' => round((float) ($avgApprovalTime ?? 0), 1),
        ];
    }

    /**
     * Get campaign sources breakdown.
     */
    public function sourceBreakdown(string $dateFrom, string $dateTo): array
    {
        return Booking::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select('source', DB::raw('COUNT(*) as count'), DB::raw('SUM(final_amount) as revenue'))
            ->groupBy('source')
            ->get()
            ->toArray();
    }

    /**
     * Get daily booking volumes for charting.
     */
    public function dailyVolumes(string $dateFrom, string $dateTo): array
    {
        $results = Booking::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as bookings'),
                DB::raw('SUM(final_amount) as revenue')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill gaps
        $daily = [];
        $current = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $dayData = $results->get($dateStr);
            $daily[] = [
                'date' => $dateStr,
                'bookings' => (int) ($dayData->bookings ?? 0),
                'revenue' => (float) ($dayData->revenue ?? 0),
            ];
            $current->addDay();
        }

        return $daily;
    }
}
