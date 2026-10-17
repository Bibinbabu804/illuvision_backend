<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use App\Services\CampaignPricingService;
use App\Services\BookingCapacityService;
use App\Services\InventoryService;
use App\Services\ManualBookingService;
use App\Services\PricingRuleEngine;
use App\Services\RevenueDistributionService;
use App\Support\DurationFormatter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinuteFullRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected Country $country;
    protected State $state;
    protected City $city;
    protected ScreenCategory $category;

    protected PricingPlan $minutePlan;
    protected PricingPlan $hourPlan;
    protected PricingPlan $dayPlan;

    protected Screen $screenA_30m;
    protected Screen $screenB_1h;
    protected Screen $screenC_1d;
    protected Screen $mobileFleet;

    protected PricingRuleEngine $ruleEngine;
    protected CampaignPricingService $pricingService;
    protected InventoryService $inventoryService;
    protected BookingCapacityService $capacityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ruleEngine = app(PricingRuleEngine::class);
        $this->pricingService = app(CampaignPricingService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->capacityService = app(BookingCapacityService::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->client = User::factory()->create(['role' => 'advertiser', 'status' => 'active']);

        $this->country = Country::create(['name' => 'India', 'code' => 'IN', 'phone_code' => '91']);
        $this->state = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::create(['name' => 'Kochi', 'country_id' => $this->country->id, 'state_id' => $this->state->id]);
        $this->category = ScreenCategory::create(['name' => 'Commercial LED']);

        // Pricing Plans with distinct minimum durations
        $this->minutePlan = PricingPlan::create([
            'name' => '30-Minute Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 500, // per day base
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        $this->hourPlan = PricingPlan::create([
            'name' => '1-Hour Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 1000,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'hour',
            'is_active' => true,
        ]);

        $this->dayPlan = PricingPlan::create([
            'name' => '1-Day Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 2000,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'day',
            'is_active' => true,
        ]);

        // Screens
        $this->screenA_30m = Screen::create([
            'name' => 'Marine Drive Screen (30m min)',
            'screen_code' => 'MDR-30M-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Marine Drive',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->screenB_1h = Screen::create([
            'name' => 'MG Road Screen (1h min)',
            'screen_code' => 'MGR-1H-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'MG Road',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->hourPlan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->screenC_1d = Screen::create([
            'name' => 'Highway Billboard (1d min)',
            'screen_code' => 'HWY-1D-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'NH 66 Highway',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->dayPlan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->mobileFleet = Screen::create([
            'name' => 'Kochi Transit Fleet',
            'screen_code' => 'KOC-FLT-01',
            'screen_type' => 'mobile_digital',
            'asset_type' => 'mobile_van',
            'location_name' => 'Kochi Metro Area',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'fleet_size' => 100,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    // =========================================================================
    // SECTION 3 & 4: PRICING MATRIX & GOLDEN RESULT REGRESSION
    // =========================================================================

    public function test_pricing_matrix_across_all_durations()
    {
        $plan = PricingPlan::create([
            'name' => 'Full Matrix Dynamic Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 1440, // ₹1,440/day = ₹1/minute base
            'minimum_duration' => 1,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        $matrix = [
            ['minutes' => 1, 'start' => '10:00', 'end' => '10:01'],
            ['minutes' => 5, 'start' => '10:00', 'end' => '10:05'],
            ['minutes' => 15, 'start' => '10:00', 'end' => '10:15'],
            ['minutes' => 30, 'start' => '10:00', 'end' => '10:30'],
            ['minutes' => 45, 'start' => '10:00', 'end' => '10:45'],
            ['minutes' => 60, 'start' => '10:00', 'end' => '11:00'],
            ['minutes' => 90, 'start' => '10:00', 'end' => '11:30'],
            ['minutes' => 120, 'start' => '10:00', 'end' => '12:00'],
        ];

        foreach ($matrix as $row) {
            $result = $this->ruleEngine->calculate($plan, [
                'screens' => 1,
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-01',
                'daily_start_time' => $row['start'],
                'daily_end_time' => $row['end'],
            ]);

            $this->assertGreaterThan(0, $result['final_price']);
            $this->assertEquals(DurationFormatter::formatMinutes($row['minutes']), DurationFormatter::formatMinutes($row['minutes']));
            $this->assertCount(1, $result['daily_breakdown']);
            $this->assertEquals(round($row['minutes'] / 60.0, 2), $result['daily_breakdown'][0]['runtime_hours']);
        }
    }

    public function test_golden_results_for_existing_hour_day_month_campaigns()
    {
        // 1 Day Campaign (00:00 - 23:59)
        $result1Day = $this->ruleEngine->calculate($this->dayPlan, [
            'screens' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);
        $this->assertEquals(2000.0, (float) $result1Day['base_price']);
        $this->assertEquals(2000.0, (float) $result1Day['final_price']);
        $this->assertCount(1, $result1Day['daily_breakdown']);

        // 7 Days Campaign
        $result7Days = $this->ruleEngine->calculate($this->dayPlan, [
            'screens' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-07',
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);
        $this->assertEquals(7 * 2000.0, (float) $result7Days['base_price']);
        $this->assertEquals(7 * 2000.0, (float) $result7Days['final_price']);
        $this->assertCount(7, $result7Days['daily_breakdown']);

        // 30 Days Campaign (1 Month standard)
        $result30Days = $this->ruleEngine->calculate($this->dayPlan, [
            'screens' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);
        $this->assertEquals(30 * 2000.0, (float) $result30Days['base_price']);
        $this->assertEquals(30 * 2000.0, (float) $result30Days['final_price']);
        $this->assertCount(30, $result30Days['daily_breakdown']);
    }

    // =========================================================================
    // SECTION 6 & 7: MIXED PLAN TESTING (ASSET-LEVEL EVALUATION)
    // =========================================================================

    public function test_mixed_plan_45m_and_2h_campaign_eligibility()
    {
        $screenIds = [$this->screenA_30m->id, $this->screenB_1h->id, $this->screenC_1d->id];

        // 45-minute campaign: Screen A (min 30m) valid; Screen B (min 1h) & C (min 1d) invalid
        $errors45m = $this->pricingService->validateMinimumDuration(
            $screenIds,
            '2026-09-01',
            '2026-09-01',
            '10:00',
            '10:45'
        );
        $this->assertNotEmpty($errors45m);
        $this->assertStringContainsString('MG Road Screen', implode(' ', $errors45m));
        $this->assertStringContainsString('Highway Billboard', implode(' ', $errors45m));
        $this->assertStringNotContainsString('Marine Drive Screen', implode(' ', $errors45m));

        // 2-hour campaign: Screen A (30m) & Screen B (1h) valid; Screen C (1d) invalid
        $errors2h = $this->pricingService->validateMinimumDuration(
            $screenIds,
            '2026-09-01',
            '2026-09-01',
            '10:00',
            '12:00'
        );
        $this->assertNotEmpty($errors2h);
        $this->assertStringContainsString('Highway Billboard', implode(' ', $errors2h));
        $this->assertStringNotContainsString('Marine Drive Screen', implode(' ', $errors2h));
        $this->assertStringNotContainsString('MG Road Screen', implode(' ', $errors2h));

        // 1-day campaign: All 3 screens valid
        $errors1d = $this->pricingService->validateMinimumDuration(
            $screenIds,
            '2026-09-01',
            '2026-09-01',
            '00:00',
            '23:59'
        );
        $this->assertEmpty($errors1d);
    }

    // =========================================================================
    // SECTION 8 & 9: FIXED ASSET AVAILABILITY (TIMESTAMP OVERLAP & ADJACENCY)
    // =========================================================================

    public function test_fixed_asset_timestamp_overlap_conflict_and_adjacency_allowed()
    {
        // Existing Booking: 10:00 - 10:30
        $booking = Booking::create([
            'user_id' => $this->client->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'title' => 'Camp 1',
            'campaign_title' => 'Camp 1',
            'advertiser_name' => 'Brand A',
            'start_time' => '2026-09-01 10:00:00',
            'end_time' => '2026-09-01 10:30:00',
            'price' => 500,
            'final_amount' => 500,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->screenA_30m->id,
            'quantity' => 1,
            'subtotal' => 500,
            'status' => 'approved',
        ]);

        // Scenario A: Overlapping interval (10:15 - 10:45) -> MUST CONFLICT
        $conflictA = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-09-01 10:15:00',
            '2026-09-01 10:45:00'
        );
        $this->assertTrue($conflictA);

        // Scenario B: Adjacent interval (10:30 - 11:00) -> MUST BE ALLOWED
        $conflictB = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-09-01 10:30:00',
            '2026-09-01 11:00:00'
        );
        $this->assertFalse($conflictB);

        // Scenario C: Preceding adjacent interval (09:30 - 10:00) -> MUST BE ALLOWED
        $conflictC = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-09-01 09:30:00',
            '2026-09-01 10:00:00'
        );
        $this->assertFalse($conflictC);
    }

    public function test_fixed_asset_same_day_multiple_non_overlapping_bookings()
    {
        // 3 consecutive non-overlapping bookings on the same calendar date
        $slots = [
            ['start' => '2026-09-01 10:00:00', 'end' => '2026-09-01 10:30:00'],
            ['start' => '2026-09-01 10:30:00', 'end' => '2026-09-01 11:00:00'],
            ['start' => '2026-09-01 11:00:00', 'end' => '2026-09-01 11:30:00'],
        ];

        foreach ($slots as $idx => $slot) {
            $b = Booking::create([
                'user_id' => $this->client->id,
                'pricing_plan_id' => $this->minutePlan->id,
                'title' => "Consecutive Camp {$idx}",
                'campaign_title' => "Consecutive Camp {$idx}",
                'advertiser_name' => "Brand {$idx}",
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'price' => 500,
                'final_amount' => 500,
                'total_screens' => 1,
                'status' => Booking::STATUS_APPROVED,
            ]);
            BookingScreen::create([
                'booking_id' => $b->id,
                'screen_id' => $this->screenA_30m->id,
                'quantity' => 1,
                'subtotal' => 500,
                'status' => 'approved',
            ]);
        }

        // Mid-overlap (10:15 - 10:45) conflicts with slot 0 and 1
        $conflict = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-09-01 10:15:00',
            '2026-09-01 10:45:00'
        );
        $this->assertTrue($conflict);

        // Later free slot (11:30 - 12:00) is available
        $freeSlot = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-09-01 11:30:00',
            '2026-09-01 12:00:00'
        );
        $this->assertFalse($freeSlot);
    }

    // =========================================================================
    // SECTION 10, 11 & 12: MOBILE CAPACITY (OVERLAPPING, NON-OVERLAPPING & EDGES)
    // =========================================================================

    public function test_mobile_capacity_non_overlapping_and_overlapping()
    {
        // Fleet size = 100. Booking A: 10:00 - 11:00 with 60 units
        $bookingA = Booking::create([
            'user_id' => $this->client->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'title' => 'Transit Morning 60 Units',
            'campaign_title' => 'Transit Morning 60 Units',
            'advertiser_name' => 'Transit Brand A',
            'start_time' => '2026-09-01 10:00:00',
            'end_time' => '2026-09-01 11:00:00',
            'price' => 3000,
            'final_amount' => 3000,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $bookingA->id,
            'screen_id' => $this->mobileFleet->id,
            'quantity' => 60,
            'subtotal' => 3000,
            'status' => 'approved',
        ]);

        // Scenario 1: Non-overlapping Booking B (14:00 - 15:00, 50 units) -> ALLOWED
        $nonOverlapErrors = $this->inventoryService->validateFleetAvailability(
            [['asset_id' => $this->mobileFleet->id, 'quantity' => 50]],
            '2026-09-01 14:00:00',
            '2026-09-01 15:00:00'
        );
        $this->assertEmpty($nonOverlapErrors);

        // Scenario 2: Overlapping Booking B (10:30 - 11:30, 50 units) -> CONFLICT (60 + 50 = 110 > 100)
        $overlapErrors = $this->inventoryService->validateFleetAvailability(
            [['asset_id' => $this->mobileFleet->id, 'quantity' => 50]],
            '2026-09-01 10:30:00',
            '2026-09-01 11:30:00'
        );
        $this->assertNotEmpty($overlapErrors);
        $this->assertStringContainsString('only 40 units available', implode(' ', $overlapErrors));

        // Scenario 3: Overlapping with acceptable quantity (10:30 - 11:30, 40 units) -> ALLOWED (60 + 40 = 100 <= 100)
        $exactCapacityErrors = $this->inventoryService->validateFleetAvailability(
            [['asset_id' => $this->mobileFleet->id, 'quantity' => 40]],
            '2026-09-01 10:30:00',
            '2026-09-01 11:30:00'
        );
        $this->assertEmpty($exactCapacityErrors);

        // Scenario 4: Overlapping with 41 units -> CONFLICT (60 + 41 = 101 > 100)
        $overByOneErrors = $this->inventoryService->validateFleetAvailability(
            [['asset_id' => $this->mobileFleet->id, 'quantity' => 41]],
            '2026-09-01 10:30:00',
            '2026-09-01 11:30:00'
        );
        $this->assertNotEmpty($overByOneErrors);
    }

    // =========================================================================
    // SECTION 16, 17, 18: ADMIN, PROVIDER & PARTNER CONSISTENCY
    // =========================================================================

    public function test_admin_and_provider_booking_financial_and_duration_consistency()
    {
        $provider = ScreenProvider::create([
            'provider_name' => 'Kochi Media Network',
            'business_name' => 'Kochi Media Ltd',
            'business_type' => 'company',
            'email' => 'kmn@example.com',
            'phone' => '9847000001',
            'status' => 'approved',
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        $providerScreen = Screen::create([
            'name' => 'Kochi Junction LED',
            'screen_code' => 'KOC-JNC-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Junction Plaza',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $manualBookingService = app(ManualBookingService::class);
        $booking = $manualBookingService->createCampaignBooking([
            'client' => ['name' => 'Client X', 'email' => 'clientx@example.com'],
            'screen_ids' => [$providerScreen->id],
            'quantities' => [$providerScreen->id => 1],
            'start_time' => '2026-09-01 10:00:00',
            'end_time' => '2026-09-01 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'total_screens' => 1,
            'subtotal' => 500,
            'discount_amount' => 0,
            'final_amount' => 500,
            'payment_mode' => 'offline',
            'bypass_approval' => true,
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Admin 30m Campaign',
            'ad_frequency' => 'standard',
        ], $this->admin);

        $this->assertEquals(30, $booking->duration_minutes);
        $this->assertEquals('30 Minutes', $booking->duration_label);

        $bookingScreen = BookingScreen::where('booking_id', $booking->id)->first();
        $this->assertNotNull($bookingScreen);
        $this->assertEquals(80.00, (float) $bookingScreen->screen_provider_percentage);
        $this->assertEquals(20.00, (float) $bookingScreen->illuvision_percentage);

        $finalAmount = (float) $booking->final_amount;
        $expectedProviderShare = round($finalAmount * 0.80, 2);
        $expectedPlatformShare = round($finalAmount * 0.20, 2);

        $this->assertEquals($expectedProviderShare, (float) $bookingScreen->provider_share);
        $this->assertEquals($expectedPlatformShare, (float) $bookingScreen->platform_share);

        // Provider booking history check
        $revService = app(RevenueDistributionService::class);
        $history = $revService->getProviderBookingHistory($provider->id);
        $item = $history->items()[0];

        $this->assertEquals(30, $item->duration_minutes);
        $this->assertEquals('30 Minutes', $item->duration_label);
        $this->assertEquals($expectedProviderShare, (float) $item->provider_share);
    }

    // =========================================================================
    // SECTION 41 & 42: DATE/TIME BOUNDARIES & INVALID INPUT VALIDATION
    // =========================================================================

    public function test_duration_below_plan_minimum_rejected()
    {
        // Screen B requires 1 hour (60 minutes). Try 30 minutes.
        $errors = $this->pricingService->validateMinimumDuration(
            [$this->screenB_1h->id],
            '2026-09-01',
            '2026-09-01',
            '10:00',
            '10:30' // 30 minutes < 60 minutes
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('MG Road Screen', implode(' ', $errors));
    }

    public function test_same_day_cross_boundary_2330_to_2359()
    {
        $result = $this->ruleEngine->calculate($this->minutePlan, [
            'screens' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'daily_start_time' => '23:30',
            'daily_end_time' => '23:59',
        ]);

        $this->assertGreaterThan(0, $result['final_price']);
        $this->assertCount(1, $result['daily_breakdown']);
        $this->assertEquals(round(29 / 60.0, 2), $result['daily_breakdown'][0]['runtime_hours']);
    }

    // =========================================================================
    // SECTION 63: GOLDEN END-TO-END SCENARIO
    // =========================================================================

    public function test_golden_e2e_30_minute_campaign_lifecycle()
    {
        // 1. Availability validation
        $hasConflict = $this->inventoryService->hasFixedScreenConflict(
            $this->screenA_30m->id,
            '2026-08-28 10:00:00',
            '2026-08-28 10:30:00'
        );
        $this->assertFalse($hasConflict);

        // 2. Authoritative pricing calculation
        $result = $this->ruleEngine->calculate($this->minutePlan, [
            'screens' => 1,
            'start_date' => '2026-08-28',
            'end_date' => '2026-08-28',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ]);
        $this->assertGreaterThan(0, $result['final_price']);

        // 3. Final booking creation
        $booking = Booking::create([
            'user_id' => $this->client->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'title' => 'Golden 30m Campaign',
            'campaign_title' => 'Golden 30m Campaign',
            'advertiser_name' => 'Golden Advertiser',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'price' => $result['base_price'],
            'final_amount' => $result['final_price'],
            'pricing_snapshot' => $result,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $this->assertEquals(30, $booking->duration_minutes);
        $this->assertEquals('30 Minutes', $booking->duration_label);
        $this->assertNotEquals('0 Days', $booking->duration_label);
        $this->assertNotEquals('1 Day', $booking->duration_label);
        $this->assertNotEquals('30 Hours', $booking->duration_label);
    }
}
