<?php

namespace Tests\Feature\Reporting;

use App\Application\Reporting\ReportQueryRegistry;
use App\Models\Booking;
use App\Models\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard / KPI regression coverage for the Phase 0.11 reporting query layer.
 *
 * These assertions pin the exact KPI formulas documented in
 * docs/audit/phase-0.11-metric-definition-map.json so that a future refactor
 * cannot silently change a reported number.
 */
class DashboardKpiReportTest extends TestCase
{
    use RefreshDatabase;
    use ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportingFixtures();
    }

    protected function overview(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('overview')->execute($filters);
    }

    public function test_overview_kpis_match_canonical_revenue_and_booking_definitions(): void
    {
        $screen = Screen::create([
            'name' => 'MG Road Screen',
            'screen_code' => 'KOC-MGR-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'MG Road',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // 1000 revenue, 100 referral discount, 10 ordinary discount.
        $this->makeBooking([
            'final_amount' => 1000,
            'discount_amount' => 10,
            'referral_discount' => 100,
            'created_at' => '2026-08-05 09:00:00',
            'start_time' => '2026-08-05 10:00:00',
            'end_time' => '2026-08-05 11:00:00',
            'screen_id' => $screen->id,
        ]);

        // 500 revenue, cancelled -> must be excluded from every revenue KPI.
        $this->makeBooking([
            'final_amount' => 500,
            'discount_amount' => 0,
            'referral_discount' => 0,
            'status' => Booking::STATUS_CANCELLED,
            'created_at' => '2026-08-06 09:00:00',
            'start_time' => '2026-08-06 10:00:00',
            'end_time' => '2026-08-06 11:00:00',
        ]);

        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        // total_revenue = SUM(final_amount) WHERE status != cancelled
        $this->assertEquals(1000.0, $kpis['total_revenue']);

        // referral_revenue maps to referral_discounts on the revenue read model
        $this->assertEquals(100.0, $kpis['referral_revenue']);

        // total_bookings counts ALL bookings in range (cancelled included) —
        // this asymmetry against total_revenue is pre-existing and documented.
        $this->assertEquals(2, $kpis['total_bookings']);

        $summary = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(1000.0, $summary['total_revenue']);
        $this->assertEquals(10.0, $summary['total_discounts']);
        $this->assertEquals(100.0, $summary['referral_discounts']);
    }

    public function test_overview_growth_rates_are_zero_when_both_periods_are_empty(): void
    {
        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        // Empty current period and empty comparison period -> 0, not a division error.
        $this->assertEquals(0.0, $kpis['revenue_growth']);
        $this->assertEquals(0.0, $kpis['booking_growth']);
    }

    public function test_overview_growth_rate_compares_against_the_immediately_preceding_window(): void
    {
        // Previous window (Jul 01 - Jul 31) holds 1000.
        $this->makeBooking([
            'final_amount' => 1000,
            'created_at' => '2026-07-15 09:00:00',
        ]);

        // Current window (Aug 01 - Aug 31) holds 1500.
        $this->makeBooking([
            'final_amount' => 1500,
            'created_at' => '2026-08-15 09:00:00',
        ]);

        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        // (1500 - 1000) / 1000 * 100 = 50.0
        $this->assertEquals(50.0, $kpis['revenue_growth']);
    }

    public function test_overview_excludes_bookings_outside_the_requested_window(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'created_at' => '2026-08-10 09:00:00',
        ]);
        $this->makeBooking([
            'final_amount' => 7777,
            'created_at' => '2026-09-10 09:00:00',
        ]);

        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(1000.0, $kpis['total_revenue']);
    }

    public function test_overview_includes_late_final_day_bookings_when_date_to_is_normalised_by_the_http_layer(): void
    {
        // Late on the final day of the window. ReportController::parseFilters()
        // rewrites date_to to endOfDay, so the row is inside the window.
        $this->makeBooking([
            'final_amount' => 300,
            'created_at' => '2026-08-31 23:30:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/reports/overview?date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200)
            ->assertJsonPath('data.kpis.total_revenue', 300);
    }

    public function test_overview_excludes_late_final_day_bookings_when_a_bare_date_is_passed_straight_to_the_query(): void
    {
        $this->makeBooking([
            'final_amount' => 300,
            'created_at' => '2026-08-31 23:30:00',
        ]);

        // Documented asymmetry: the query layer passes filter values through
        // untouched, so a bare "Y-m-d" date_to is read as 00:00:00 and later
        // rows on that day fall outside the window. Only the HTTP layer widens
        // the boundary. Pinned so a future "helpful" normalisation in
        // ReportPeriod::resolve() is caught.
        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
        ])['kpis'];

        $this->assertEquals(0.0, $kpis['total_revenue']);
    }

    public function test_overview_includes_a_booking_at_the_exact_end_of_day_boundary(): void
    {
        $this->makeBooking([
            'final_amount' => 300,
            'created_at' => '2026-08-31 23:59:59',
        ]);

        $kpis = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(300.0, $kpis['total_revenue']);
    }

    public function test_overview_defaults_to_the_current_calendar_month_when_no_dates_are_supplied(): void
    {
        $this->makeBooking([
            'final_amount' => 900,
            'created_at' => now()->format('Y-m-d').' 09:00:00',
        ]);

        $kpis = $this->overview()['kpis'];

        $this->assertEquals(900.0, $kpis['total_revenue']);
    }

    public function test_overview_chart_series_cover_every_day_in_the_window(): void
    {
        $this->makeBooking([
            'final_amount' => 100,
            'created_at' => '2026-08-01 09:00:00',
        ]);

        $charts = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['charts'];

        // Zero filled series, one row per calendar day.
        $this->assertCount(31, $charts['revenue_trends']);
        $this->assertCount(31, $charts['booking_trends']);
        $this->assertEquals('2026-08-01', $charts['revenue_trends'][0]['date']);
        $this->assertEquals('2026-08-31', $charts['revenue_trends'][30]['date']);
        $this->assertEquals(100.0, $charts['revenue_trends'][0]['revenue']);
        $this->assertEquals(0.0, $charts['revenue_trends'][1]['revenue']);
    }

    public function test_overview_endpoint_returns_the_unchanged_response_envelope(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/reports/overview?date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Report overview fetched successfully')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'kpis' => [
                        'total_revenue',
                        'revenue_growth',
                        'total_bookings',
                        'booking_growth',
                        'active_campaigns',
                        'pending_approvals',
                        'average_occupancy',
                        'referral_revenue',
                    ],
                    'charts' => [
                        'revenue_trends',
                        'booking_trends',
                        'screen_utilization',
                        'top_cities',
                    ],
                    'summary' => [
                        'total_revenue',
                        'total_bookings',
                        'total_screens',
                        'active_screens',
                        'total_discounts',
                        'referral_discounts',
                        'total_commissions',
                        'net_revenue',
                    ],
                ],
            ]);
    }

    public function test_occupancy_rate_is_reported_from_the_screen_read_model(): void
    {
        $screen = Screen::create([
            'name' => 'Occupancy Screen',
            'screen_code' => 'KOC-OCC-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Occupancy',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->makeBooking([
            'screen_id' => $screen->id,
            'start_time' => '2026-08-10 10:00:00',
            'end_time' => '2026-08-10 11:00:00',
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $data = $this->overview([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertEquals(1, $data['summary']['total_screens']);
        $this->assertEquals(1, $data['summary']['active_screens']);
        $this->assertGreaterThan(0, $data['kpis']['average_occupancy']);
    }
}
