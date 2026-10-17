<?php

namespace Tests\Feature\Reporting;

use App\Application\Reporting\ReportQueryRegistry;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Financial reporting regression coverage.
 *
 * The screen provider report is the highest-risk surface in Phase 0.11 because
 * it aggregates persisted revenue splits. These tests assert that the report
 * sums the stored booking_screens columns and never re-derives them, and that
 * settlement status segmentation stays correct.
 */
class ScreenProviderFinancialReportTest extends TestCase
{
    use RefreshDatabase;
    use ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportingFixtures();
    }

    protected function report(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('screen-providers')->execute($filters);
    }

    public function test_it_sums_persisted_revenue_splits_rather_than_recomputing_them(): void
    {
        $provider = $this->makeProvider();
        $screen = $this->makeProviderScreen($provider);

        $booking = $this->makeBooking([
            'final_amount' => 1000,
            // Deliberately inconsistent with the split below: the report must
            // trust booking_screens, not re-derive 85/15 from final_amount.
            'start_time' => '2026-08-10 10:00:00',
            'end_time' => '2026-08-10 11:00:00',
        ]);

        $this->attachBookingScreen($booking, $screen, [
            'gross_revenue' => 1000,
            'platform_share' => 150,
            'provider_share' => 850,
            'settlement_status' => 'pending',
        ]);

        $summary = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(1000.0, $summary['total_gross_revenue']);
        $this->assertEquals(150.0, $summary['total_platform_revenue']);
        $this->assertEquals(850.0, $summary['total_provider_revenue']);
        $this->assertEquals(1, $summary['total_providers']);
        $this->assertEquals(1, $summary['total_owned_screens']);
    }

    public function test_settlement_status_segments_pending_and_settled_amounts(): void
    {
        $provider = $this->makeProvider();
        $screenA = $this->makeProviderScreen($provider, 'Pending Screen', 'APX-PND-01');
        $screenB = $this->makeProviderScreen($provider, 'Settled Screen', 'APX-STL-01');

        $pendingBooking = $this->makeBooking([
            'final_amount' => 1000,
            'end_time' => '2026-08-10 11:00:00',
        ]);
        $this->attachBookingScreen($pendingBooking, $screenA, [
            'provider_share' => 850,
            'platform_share' => 150,
            'gross_revenue' => 1000,
            'settlement_status' => 'pending',
        ]);

        $settledBooking = $this->makeBooking([
            'final_amount' => 2000,
            'end_time' => '2026-08-11 11:00:00',
        ]);
        $this->attachBookingScreen($settledBooking, $screenB, [
            'provider_share' => 1700,
            'platform_share' => 300,
            'gross_revenue' => 2000,
            'settlement_status' => 'settled',
        ]);

        $summary = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(850.0, $summary['pending_settlement']);
        $this->assertEquals(1700.0, $summary['settled_amount']);

        // outstanding_liability is defined as the pending provider share.
        $this->assertEquals(850.0, $summary['outstanding_liability']);
        $this->assertEquals(2550.0, $summary['total_provider_revenue']);
    }

    public function test_it_excludes_bookings_whose_status_is_outside_the_financial_status_set(): void
    {
        $provider = $this->makeProvider();
        $screen = $this->makeProviderScreen($provider);

        $counted = [
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
        ];

        $ignored = [
            Booking::STATUS_CANCELLED,
            Booking::STATUS_PENDING_APPROVAL,
            Booking::STATUS_REJECTED,
            Booking::STATUS_DRAFT,
        ];

        $day = 1;
        foreach (array_merge($counted, $ignored) as $status) {
            $booking = $this->makeBooking([
                'final_amount' => 1000,
                'status' => $status,
                'end_time' => sprintf('2026-08-%02d 11:00:00', $day++),
            ]);
            $this->attachBookingScreen($booking, $screen, [
                'gross_revenue' => 1000,
                'platform_share' => 150,
                'provider_share' => 850,
            ]);
        }

        $summary = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        // Only the four revenue-bearing statuses contribute.
        $this->assertEquals(4000.0, $summary['total_gross_revenue']);
        $this->assertEquals(3400.0, $summary['total_provider_revenue']);
        $this->assertEquals(600.0, $summary['total_platform_revenue']);
    }

    public function test_it_filters_by_provider_and_settlement_status(): void
    {
        $apex = $this->makeProvider('Apex Displays', 'Apex Media Ltd');
        $zenith = $this->makeProvider('Zenith Media', 'Zenith Pvt Ltd');

        $apexScreen = $this->makeProviderScreen($apex, 'Apex One', 'APX-ONE-01');
        $zenithScreen = $this->makeProviderScreen($zenith, 'Zenith One', 'ZEN-ONE-01');

        $apexBooking = $this->makeBooking(['final_amount' => 1000, 'end_time' => '2026-08-10 11:00:00']);
        $this->attachBookingScreen($apexBooking, $apexScreen, [
            'gross_revenue' => 1000, 'platform_share' => 150, 'provider_share' => 850,
        ]);

        $zenithBooking = $this->makeBooking(['final_amount' => 4000, 'end_time' => '2026-08-11 11:00:00']);
        $this->attachBookingScreen($zenithBooking, $zenithScreen, [
            'gross_revenue' => 4000, 'platform_share' => 600, 'provider_share' => 3400,
        ]);

        $apexOnly = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
            'screen_provider_id' => $apex->id,
        ])['summary'];

        $this->assertEquals(1, $apexOnly['total_providers']);
        $this->assertEquals(1000.0, $apexOnly['total_gross_revenue']);

        $settledOnly = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
            'settlement_status' => 'settled',
        ])['summary'];

        $this->assertEquals(0.0, $settledOnly['total_gross_revenue']);
    }

    public function test_it_groups_provider_and_screen_breakdowns_with_stored_values(): void
    {
        $provider = $this->makeProvider();
        $screen = $this->makeProviderScreen($provider);

        $booking = $this->makeBooking(['final_amount' => 1000, 'end_time' => '2026-08-10 11:00:00']);
        $this->attachBookingScreen($booking, $screen, [
            'gross_revenue' => 1000, 'platform_share' => 150, 'provider_share' => 850,
        ]);

        $report = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $this->assertCount(1, $report['provider_breakdown']);

        // Breakdown rows come from the query builder, so they are stdClass
        // instances rather than associative arrays. json_encode renders both
        // identically, which is why the API payload is unaffected.
        $providerRow = (array) $report['provider_breakdown'][0];

        $this->assertEquals($provider->id, $providerRow['provider_id']);
        $this->assertEquals('Apex Displays', $providerRow['provider_name']);
        $this->assertEquals('Apex Media Ltd', $providerRow['business_name']);
        $this->assertEquals(1, (int) $providerRow['owned_screens']);
        $this->assertEquals(1, (int) $providerRow['campaign_count']);
        $this->assertEquals(1000.0, (float) $providerRow['gross_revenue']);
        $this->assertEquals(850.0, (float) $providerRow['provider_revenue']);
        $this->assertEquals(850.0, (float) $providerRow['pending_settlement']);
        $this->assertEquals(850.0, (float) $providerRow['outstanding_balance']);

        $this->assertCount(1, $report['screen_breakdown']);
        $screenRow = (array) $report['screen_breakdown'][0];
        $this->assertEquals('Kochi', $screenRow['city_name']);
        $this->assertEquals('India', $screenRow['country_name']);
        $this->assertEquals(1000.0, (float) $screenRow['avg_revenue']);
    }

    public function test_report_window_is_driven_by_booking_end_time_not_created_at(): void
    {
        $provider = $this->makeProvider();
        $screen = $this->makeProviderScreen($provider);

        // Created in August, ends in September -> outside an August window.
        $booking = $this->makeBooking([
            'final_amount' => 1000,
            'created_at' => '2026-08-10 09:00:00',
            'start_time' => '2026-08-31 23:00:00',
            'end_time' => '2026-09-01 01:00:00',
        ]);
        $this->attachBookingScreen($booking, $screen, [
            'gross_revenue' => 1000, 'platform_share' => 150, 'provider_share' => 850,
        ]);

        $august = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $september = $this->report([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30 23:59:59',
        ])['summary'];

        $this->assertEquals(0.0, $august['total_gross_revenue']);
        $this->assertEquals(1000.0, $september['total_gross_revenue']);
    }

    public function test_decimal_precision_is_preserved_for_fractional_revenue_shares(): void
    {
        $provider = $this->makeProvider();
        $screen = $this->makeProviderScreen($provider);

        $booking = $this->makeBooking(['final_amount' => 333.33, 'end_time' => '2026-08-10 11:00:00']);
        $this->attachBookingScreen($booking, $screen, [
            'gross_revenue' => 333.33,
            'platform_share' => 50.00,
            'provider_share' => 283.33,
        ]);

        $summary = $this->report([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['summary'];

        $this->assertEquals(333.33, $summary['total_gross_revenue']);
        $this->assertEquals(283.33, $summary['total_provider_revenue']);
        $this->assertEquals(50.00, $summary['total_platform_revenue']);
    }

    public function test_screen_provider_endpoint_preserves_its_response_shape(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/reports/screen-providers?date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Screen provider report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'total_providers',
                        'total_provider_revenue',
                        'total_platform_revenue',
                        'total_gross_revenue',
                        'pending_settlement',
                        'settled_amount',
                        'outstanding_liability',
                        'total_owned_screens',
                    ],
                    'provider_breakdown',
                    'screen_breakdown',
                ],
            ]);
    }

    public function test_revenue_report_net_revenue_subtracts_only_paid_commissions(): void
    {
        [$partner, $code] = $this->makePartnerReferral();

        $this->makeBooking([
            'final_amount' => 1000,
            'referral_discount' => 100,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $usage = $this->makeReferralUsage($partner, $code, 100, 50, '2026-08-10 09:00:00');

        $this->makeCommission($partner, $usage, 'paid', 50, '2026-08-10 09:00:00');
        $this->makeCommission($partner, $usage, 'pending', 999, '2026-08-10 09:00:00');

        $overview = app(ReportQueryRegistry::class)->get('revenue')->execute([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['overview'];

        // Only the paid commission reduces net revenue.
        $this->assertEquals(1000.0, $overview['total_revenue']);
        $this->assertEquals(50.0, $overview['total_commissions']);
        $this->assertEquals(950.0, $overview['net_revenue']);
        $this->assertEquals(1000.0, $overview['average_campaign_value']);
    }
}
