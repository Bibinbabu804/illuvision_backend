<?php

namespace App\Application\Reporting\Queries;

use App\Application\Reporting\Contracts\ReportQuery;
use App\Application\Reporting\Support\ReportPeriod;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Advertisement category report.
 *
 * Groups bookings by the denormalised `advertisement_category_name` column,
 * preserving the existing grouping key (including its NULL handling, where the
 * selected alias coalesces to "Other" while the GROUP BY remains on the raw
 * nullable column).
 */
class CategoryReportQuery implements ReportQuery
{
    /**
     * {@inheritdoc}
     */
    public function execute(array $filters = []): array
    {
        ['date_from' => $dateFrom, 'date_to' => $dateTo] = ReportPeriod::resolve($filters);

        // Base query
        $query = DB::table('bookings')
            ->whereBetween('bookings.created_at', [$dateFrom, $dateTo])
            ->where('bookings.status', '!=', Booking::STATUS_CANCELLED);

        // Apply category filter if provided
        if (isset($filters['advertisement_category_id']) && ! empty($filters['advertisement_category_id'])) {
            $query->where('bookings.advertisement_category_id', $filters['advertisement_category_id']);
        }

        // Apply country filter
        if (isset($filters['country_id']) && ! empty($filters['country_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('booking_screens')
                        ->join('screens', 'screens.id', '=', 'booking_screens.screen_id')
                        ->whereColumn('booking_screens.booking_id', 'bookings.id')
                        ->where('screens.country_id', $filters['country_id']);
                })->orWhereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('screens')
                        ->whereColumn('screens.id', 'bookings.screen_id')
                        ->where('screens.country_id', $filters['country_id']);
                });
            });
        }

        // Apply city filter
        if (isset($filters['city_id']) && ! empty($filters['city_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('booking_screens')
                        ->join('screens', 'screens.id', '=', 'booking_screens.screen_id')
                        ->whereColumn('booking_screens.booking_id', 'bookings.id')
                        ->where('screens.city_id', $filters['city_id']);
                })->orWhereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('screens')
                        ->whereColumn('screens.id', 'bookings.screen_id')
                        ->where('screens.city_id', $filters['city_id']);
                });
            });
        }

        // Apply provider filter
        if (isset($filters['provider_id']) && ! empty($filters['provider_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('booking_screens')
                        ->join('screens', 'screens.id', '=', 'booking_screens.screen_id')
                        ->whereColumn('booking_screens.booking_id', 'bookings.id')
                        ->where('screens.screen_provider_id', $filters['provider_id']);
                })->orWhereExists(function ($sub) use ($filters) {
                    $sub->select(DB::raw(1))
                        ->from('screens')
                        ->whereColumn('screens.id', 'bookings.screen_id')
                        ->where('screens.screen_provider_id', $filters['provider_id']);
                });
            });
        }

        // 1. Revenue by Category
        $revenueByCategory = (clone $query)
            ->select(
                DB::raw('COALESCE(advertisement_category_name, "Other") as name'),
                DB::raw('SUM(final_amount) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('advertisement_category_name')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();

        // 2. Bookings & Campaign counts by Category
        $bookingsByCategory = (clone $query)
            ->select(
                DB::raw('COALESCE(advertisement_category_name, "Other") as name'),
                DB::raw('COUNT(*) as total_bookings'),
                DB::raw('SUM(CASE WHEN total_screens > 1 THEN 1 ELSE 0 END) as campaign_bookings')
            )
            ->groupBy('advertisement_category_name')
            ->orderByDesc('total_bookings')
            ->get()
            ->toArray();

        // 3. Revenue Trend by Category (grouped by date)
        $trendByCategory = (clone $query)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COALESCE(advertisement_category_name, "Other") as name'),
                DB::raw('SUM(final_amount) as revenue')
            )
            ->groupBy('date', 'advertisement_category_name')
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'revenue_by_category' => $revenueByCategory,
            'bookings_by_category' => $bookingsByCategory,
            'trend_by_category' => $trendByCategory,
            'top_categories' => array_slice($revenueByCategory, 0, 5),
        ];
    }
}
