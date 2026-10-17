<?php

namespace Tests\Feature\Reporting;

use App\Application\Reporting\ReportQueryRegistry;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campaign and advertisement category report regression coverage.
 */
class CampaignCategoryReportTest extends TestCase
{
    use RefreshDatabase;
    use ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportingFixtures();
    }

    protected function campaigns(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('campaigns')->execute($filters);
    }

    protected function categories(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('categories')->execute($filters);
    }

    /**
     * Query-builder rows arrive as stdClass instances. json_encode renders them
     * identically to associative arrays, so the API payload is unaffected; this
     * helper only makes the assertions readable.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function rows(array $rows): array
    {
        return array_map(static fn ($row) => (array) $row, $rows);
    }

    protected function makeOwnedScreen(string $name = 'Campaign Screen', string $code = 'KOC-CMP-01'): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'screen_type' => 'digital_billboard',
            'location_name' => $name,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    public function test_campaign_report_aggregates_duration_in_canonical_minutes(): void
    {
        $screen = $this->makeOwnedScreen();

        foreach ([['10:00', '10:30', 30], ['11:00', '12:00', 60], ['13:00', '14:30', 90]] as $i => [$start, $end, $minutes]) {
            $booking = $this->makeBooking([
                'campaign_title' => "Campaign {$minutes}m",
                'start_time' => "2026-08-28 {$start}:00",
                'end_time' => "2026-08-28 {$end}:00",
                'final_amount' => 100 * ($i + 1),
                'created_at' => '2026-08-28 08:00:00',
            ]);
            BookingScreen::create([
                'booking_id' => $booking->id,
                'screen_id' => $screen->id,
                'quantity' => 1,
                'subtotal' => 100 * ($i + 1),
                'status' => 'approved',
            ]);
        }

        $summary = $this->campaigns([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(3, $summary['total_campaigns']);
        $this->assertEquals(180, $summary['total_duration_minutes']);
        $this->assertEquals('3 Hours', $summary['total_duration_label']);
        $this->assertEquals(60, $summary['average_duration_minutes']);
        $this->assertEquals('1 Hour', $summary['average_duration_label']);
    }

    public function test_campaign_report_separates_active_and_completed_campaigns(): void
    {
        $screen = $this->makeOwnedScreen();

        $statuses = [
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
            Booking::STATUS_PENDING_APPROVAL,
        ];

        foreach ($statuses as $i => $status) {
            $booking = $this->makeBooking([
                'campaign_title' => "Campaign {$status}",
                'status' => $status,
                'final_amount' => 100,
                'created_at' => '2026-08-28 08:00:00',
            ]);
            BookingScreen::create([
                'booking_id' => $booking->id,
                'screen_id' => $screen->id,
                'quantity' => 1,
                'subtotal' => 100,
                'status' => 'approved',
            ]);
        }

        $report = $this->campaigns([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        // Active = approved + scheduled + running only.
        $this->assertEquals(3, $report['summary']['active_count']);
        $this->assertEquals(1, $report['summary']['completed_count']);
        $this->assertEquals(5, $report['summary']['total_campaigns']);
        $this->assertCount(3, $report['active_campaigns']);
        $this->assertCount(1, $report['completed_campaigns']);

        // total_value sums only the active campaigns' final_amount.
        $this->assertEquals(300, $report['summary']['total_value']);
    }

    public function test_campaign_report_handles_an_empty_window_without_dividing_by_zero(): void
    {
        $summary = $this->campaigns([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(0, $summary['total_campaigns']);
        $this->assertEquals(0, $summary['total_duration_minutes']);
        $this->assertEquals(0, $summary['average_duration_minutes']);
        $this->assertEquals(0, $summary['campaign_share_pct']);
    }

    public function test_campaign_report_excludes_campaigns_outside_the_window(): void
    {
        $screen = $this->makeOwnedScreen();

        foreach (['2026-08-10', '2026-09-10'] as $date) {
            $booking = $this->makeBooking([
                'campaign_title' => "Campaign {$date}",
                'created_at' => "{$date} 08:00:00",
            ]);
            BookingScreen::create([
                'booking_id' => $booking->id,
                'screen_id' => $screen->id,
                'quantity' => 1,
                'subtotal' => 100,
                'status' => 'approved',
            ]);
        }

        $report = $this->campaigns([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertEquals(1, $report['summary']['total_campaigns']);
    }

    public function test_campaign_endpoint_preserves_its_response_shape(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/reports/campaigns?date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Campaign report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'campaigns',
                    'active_campaigns',
                    'completed_campaigns',
                    'summary' => [
                        'total_campaigns',
                        'active_count',
                        'completed_count',
                        'average_duration_minutes',
                        'average_duration_days',
                        'average_duration_label',
                        'total_duration_minutes',
                        'total_duration_label',
                        'total_value',
                        'campaign_share_pct',
                    ],
                ],
            ]);
    }

    public function test_category_report_groups_revenue_and_bookings_by_category(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'advertisement_category_name' => 'Cinema',
            'total_screens' => 3,
            'created_at' => '2026-08-10 09:00:00',
        ]);
        $this->makeBooking([
            'final_amount' => 500,
            'advertisement_category_name' => 'Cinema',
            'total_screens' => 1,
            'created_at' => '2026-08-11 09:00:00',
        ]);
        $this->makeBooking([
            'final_amount' => 250,
            'advertisement_category_name' => 'Retail',
            'total_screens' => 1,
            'created_at' => '2026-08-12 09:00:00',
        ]);

        $report = $this->categories([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $revenueByCategory = collect($this->rows($report['revenue_by_category']))->keyBy('name');

        $this->assertEquals(1500.0, (float) $revenueByCategory['Cinema']['revenue']);
        $this->assertEquals(2, (int) $revenueByCategory['Cinema']['bookings']);
        $this->assertEquals(250.0, (float) $revenueByCategory['Retail']['revenue']);

        $bookingsByCategory = collect($this->rows($report['bookings_by_category']))->keyBy('name');
        $this->assertEquals(2, (int) $bookingsByCategory['Cinema']['total_bookings']);

        // top_categories is the first 5 of the revenue-ordered list.
        $this->assertCount(2, $report['top_categories']);
        $this->assertEquals('Cinema', $this->rows($report['top_categories'])[0]['name']);

        // Trend rows are grouped per day and category.
        $this->assertCount(3, $report['trend_by_category']);
    }

    public function test_category_report_coalesces_missing_category_names_to_other(): void
    {
        $this->makeBooking([
            'final_amount' => 400,
            'advertisement_category_name' => null,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $report = $this->categories([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertCount(1, $report['revenue_by_category']);
        $this->assertEquals('Other', $this->rows($report['revenue_by_category'])[0]['name']);
        $this->assertEquals(400.0, (float) $this->rows($report['revenue_by_category'])[0]['revenue']);
    }

    public function test_category_report_excludes_cancelled_bookings(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'advertisement_category_name' => 'Cinema',
            'created_at' => '2026-08-10 09:00:00',
        ]);
        $this->makeBooking([
            'final_amount' => 9999,
            'advertisement_category_name' => 'Cinema',
            'status' => Booking::STATUS_CANCELLED,
            'created_at' => '2026-08-11 09:00:00',
        ]);

        $report = $this->categories([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertEquals(1000.0, (float) $this->rows($report['revenue_by_category'])[0]['revenue']);
    }

    public function test_category_report_applies_the_city_filter_to_both_single_and_campaign_bookings(): void
    {
        $screen = $this->makeOwnedScreen();

        // Single-screen booking in the city.
        $this->makeBooking([
            'screen_id' => $screen->id,
            'final_amount' => 1000,
            'advertisement_category_name' => 'Cinema',
            'created_at' => '2026-08-10 09:00:00',
        ]);

        // Campaign booking routed through booking_screens in the same city.
        $campaign = $this->makeBooking([
            'final_amount' => 2000,
            'total_screens' => 2,
            'advertisement_category_name' => 'Cinema',
            'created_at' => '2026-08-11 09:00:00',
        ]);
        BookingScreen::create([
            'booking_id' => $campaign->id,
            'screen_id' => $screen->id,
            'quantity' => 2,
            'subtotal' => 2000,
            'status' => 'approved',
        ]);

        $matching = $this->categories([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
            'city_id' => $this->city->id,
        ]);

        $this->assertEquals(3000.0, (float) $this->rows($matching['revenue_by_category'])[0]['revenue']);

        // A city that owns no screens must yield nothing.
        $otherCity = \App\Models\City::create([
            'name' => 'Mumbai',
            'country_id' => $this->country->id,
            'state_id' => $this->city->state_id,
        ]);

        $nonMatching = $this->categories([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
            'city_id' => $otherCity->id,
        ]);

        $this->assertCount(0, $nonMatching['revenue_by_category']);
    }

    public function test_booking_report_returns_status_breakdown_and_approval_metrics(): void
    {
        foreach ([
            Booking::STATUS_APPROVED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
            Booking::STATUS_CANCELLED,
            Booking::STATUS_PENDING_APPROVAL,
        ] as $i => $status) {
            $this->makeBooking([
                'status' => $status,
                'final_amount' => 100,
                'created_at' => sprintf('2026-08-%02d 09:00:00', $i + 1),
            ]);
        }

        $report = app(ReportQueryRegistry::class)->get('bookings')->execute([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $overview = $report['overview'];

        $this->assertEquals(5, $overview['total_bookings']);
        $this->assertEquals(1, $overview['status_breakdown']['approved']);
        $this->assertEquals(1, $overview['status_breakdown']['cancelled']);
        $this->assertEquals(2, $overview['active_campaigns']); // approved + running

        // pending_review = media_review_pending + pending_approval. No media
        // review bookings were seeded, so this is the single pending_approval row.
        $this->assertEquals(1, $overview['pending_review']);
        $this->assertEquals(0, $overview['status_breakdown']['media_review_pending']);

        // 3 of 5 reached a non-rejected terminal/active state.
        $this->assertEquals(60.0, $overview['approval_rate']);
        $this->assertEquals(20.0, $overview['cancellation_rate']);

        $this->assertCount(31, $report['daily_volumes']);
        $this->assertArrayHasKey('total_decided', $report['approval_metrics']);
    }

    public function test_booking_and_screen_endpoints_preserve_their_response_shapes(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/bookings?date_from=2026-08-01&date_to=2026-08-31')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Booking report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'overview' => [
                        'total_bookings',
                        'campaign_bookings',
                        'single_bookings',
                        'status_breakdown',
                        'pending_review',
                        'active_campaigns',
                        'total_value',
                        'average_campaign_value',
                        'approval_rate',
                        'cancellation_rate',
                    ],
                    'daily_volumes',
                    'booking_trends',
                    'approval_metrics',
                    'source_breakdown',
                ],
            ]);

        $this->actingAs($this->admin)
            ->getJson('/api/reports/screens?date_from=2026-08-01&date_to=2026-08-31')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Screen report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'overview' => [
                        'total_screens',
                        'active_screens',
                        'inactive_screens',
                        'total_active_bookings',
                        'occupancy_rate',
                        'max_capacity',
                        'max_ads_per_day',
                    ],
                    'screen_performance',
                    'utilization_distribution',
                ],
            ]);
    }

    public function test_city_report_merges_screen_and_revenue_data_per_city(): void
    {
        $screen = $this->makeOwnedScreen('Kochi Screen', 'KOC-CITY-01');

        $this->makeBooking([
            'screen_id' => $screen->id,
            'final_amount' => 2000,
            'created_at' => '2026-08-10 09:00:00',
            'start_time' => '2026-08-10 10:00:00',
            'end_time' => '2026-08-10 11:00:00',
        ]);

        $report = app(ReportQueryRegistry::class)->get('cities')->execute([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertEquals(1, $report['total_cities']);
        $this->assertCount(1, $report['city_performance']);
        $this->assertEquals($this->city->name, $report['city_performance'][0]['city_name']);
        $this->assertEquals(1, $report['city_performance'][0]['screen_count']);
    }
}
