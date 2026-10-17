<?php

namespace App\Http\Controllers;

use App\Application\Reporting\Exports\Contracts\ReportExporter;
use App\Application\Reporting\ReportQueryRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected ReportQueryRegistry $reports,
        protected ReportExporter $exporter,
    ) {}

    /**
     * GET /reports/overview
     * Dashboard overview with all KPIs and charts.
     */
    public function overview(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('overview')->execute($filters);

        return $this->successResponse($data, 'Report overview fetched successfully');
    }

    /**
     * GET /reports/revenue
     * Revenue analytics with trends and breakdowns.
     */
    public function revenue(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('revenue')->execute($filters);

        return $this->successResponse($data, 'Revenue report fetched successfully');
    }

    /**
     * GET /reports/bookings
     * Booking analytics with status distribution and trends.
     */
    public function bookings(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('bookings')->execute($filters);

        return $this->successResponse($data, 'Booking report fetched successfully');
    }

    /**
     * GET /reports/screens
     * Screen performance analytics.
     */
    public function screens(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('screens')->execute($filters);

        return $this->successResponse($data, 'Screen report fetched successfully');
    }

    /**
     * GET /reports/cities
     * City performance analytics.
     */
    public function cities(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('cities')->execute($filters);

        return $this->successResponse($data, 'City report fetched successfully');
    }

    /**
     * GET /reports/campaigns
     * Campaign analytics.
     */
    public function campaigns(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('campaigns')->execute($filters);

        return $this->successResponse($data, 'Campaign report fetched successfully');
    }

    /**
     * GET /reports/screen-providers
     * Screen Provider financial analytics with provider, screen, and booking breakdowns.
     */
    public function screenProviders(Request $request)
    {
        $filters = $this->parseFilters($request);

        // Add additional filters for the screen provider report
        if ($request->filled('screen_provider_id')) {
            $filters['screen_provider_id'] = $request->input('screen_provider_id');
        }
        if ($request->filled('screen_id')) {
            $filters['screen_id'] = $request->input('screen_id');
        }
        if ($request->filled('country_id')) {
            $filters['country_id'] = $request->input('country_id');
        }
        if ($request->filled('business_type')) {
            $filters['business_type'] = $request->input('business_type');
        }
        if ($request->filled('settlement_status')) {
            $filters['settlement_status'] = $request->input('settlement_status');
        }

        $data = $this->reports->get('screen-providers')->execute($filters);

        return $this->successResponse($data, 'Screen provider report fetched successfully');
    }

    /**
     * GET /reports/categories
     * Category analytics.
     */
    public function categories(Request $request)
    {
        $filters = $this->parseFilters($request);
        if ($request->filled('advertisement_category_id')) {
            $filters['advertisement_category_id'] = $request->input('advertisement_category_id');
        }
        if ($request->filled('provider_id')) {
            $filters['provider_id'] = $request->input('provider_id');
        }

        $data = $this->reports->get('categories')->execute($filters);

        return $this->successResponse($data, 'Category report fetched successfully');
    }

    /**
     * GET /reports/referrals
     * Referral & partner analytics.
     */
    public function referrals(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('referrals')->execute($filters);

        return $this->successResponse($data, 'Referral report fetched successfully');
    }

    /**
     * GET /reports/rewards
     * Rewards & loyalty program analytics.
     */
    public function rewards(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->reports->get('rewards')->execute($filters);

        return $this->successResponse($data, 'Reward report fetched successfully');
    }

    /**
     * GET /reports/export
     * Export report data as CSV/JSON.
     */
    public function export(Request $request)
    {
        $request->validate([
            'type' => 'required|in:bookings,revenue,screens,cities,referrals,campaigns,screen-providers,categories',
            'format' => 'sometimes|in:csv,json,xlsx',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date',
        ]);

        $filters = $this->parseFilters($request);
        $type = $request->input('type');
        $format = $request->input('format', 'csv');

        // The `screen-providers` type is accepted by validation but has no match
        // arm, so it falls through to the empty default and the CSV exporter then
        // emits headers only. This gap predates Phase 0.11 and is preserved
        // deliberately: wiring it up would change an externally observable export
        // payload. Tracked as deferred work in
        // docs/audit/phase-0.11-0.12-final-report.md.
        $data = match ($type) {
            'bookings' => $this->reports->get('bookings')->execute($filters),
            'revenue' => $this->reports->get('revenue')->execute($filters),
            'screens' => $this->reports->get('screens')->execute($filters),
            'cities' => $this->reports->get('cities')->execute($filters),
            'campaigns' => $this->reports->get('campaigns')->execute($filters),
            'categories' => $this->reports->get('categories')->execute($filters),
            'referrals' => $this->reports->get('referrals')->execute($filters),
            'rewards' => $this->reports->get('rewards')->execute($filters),
            default => [],
        };

        if ($format === 'json') {
            return $this->successResponse($data, 'Export data fetched successfully');
        }

        // CSV export
        $export = $this->exporter->export($type, $data);

        return response($export['body'], 200, [
            'Content-Type' => $export['contentType'],
            'Content-Disposition' => "attachment; filename=\"{$export['fileName']}\"",
        ]);
    }

    /**
     * Parse common filter parameters from request.
     */
    protected function parseFilters(Request $request): array
    {
        $filters = [];

        if ($dateFrom = $request->input('date_from')) {
            $filters['date_from'] = Carbon::parse($dateFrom)->format('Y-m-d');
        }

        if ($dateTo = $request->input('date_to')) {
            $filters['date_to'] = Carbon::parse($dateTo)->endOfDay()->format('Y-m-d H:i:s');
        }

        if ($request->filled('city_id')) {
            $filters['city_id'] = $request->input('city_id');
        }

        if ($request->filled('screen_id')) {
            $filters['screen_id'] = $request->input('screen_id');
        }

        if ($request->filled('pricing_plan_id')) {
            $filters['pricing_plan_id'] = $request->input('pricing_plan_id');
        }

        if ($request->filled('status')) {
            $filters['status'] = $request->input('status');
        }

        if ($request->filled('partner_id')) {
            $filters['partner_id'] = $request->input('partner_id');
        }

        return $filters;
    }
}
