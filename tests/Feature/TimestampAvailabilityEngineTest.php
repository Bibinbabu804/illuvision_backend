<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\BookingCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimestampAvailabilityEngineTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryService $inventoryService;
    protected BookingCapacityService $capacityService;
    protected Country $country;
    protected City $city;
    protected PricingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryService = app(InventoryService::class);
        $this->capacityService = app(BookingCapacityService::class);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India', 'is_active' => true]);
        $this->city = City::create(['country_id' => $this->country->id, 'name' => 'Kochi', 'is_active' => true]);
        $this->plan = PricingPlan::create([
            'name' => 'Default Plan',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);
        Setting::setGroup('booking', ['max_ads_per_day' => 1]);
    }

    /**
     * Fixed Screen: Test comprehensive interval collision matrix.
     */
    public function test_fixed_screen_interval_conflict_matrix()
    {
        $screen = Screen::create([
            'name' => 'MG Road Billboard',
            'screen_code' => 'FX-001',
            'screen_type' => 'Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // 1. No existing booking -> Available (10:00 to 10:30)
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));

        // Create an existing booking: 28 Aug 10:00:00 to 10:30:00
        $booking = Booking::create([
            'title' => 'Existing Campaign',
            'advertiser_name' => 'Acme Corp',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'subtotal' => 500.00,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // 2. Exact Overlap: 10:00-10:30 -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));

        // 3. Partial Overlap at Start: 09:45-10:15 -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 09:45:00', '2026-08-28 10:15:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 09:45:00', '2026-08-28 10:15:00'));

        // 4. Partial Overlap at End: 10:15-10:45 -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:15:00', '2026-08-28 10:45:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:15:00', '2026-08-28 10:45:00'));

        // 5. Enclosing Overlap: 09:30-11:00 -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 09:30:00', '2026-08-28 11:00:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 09:30:00', '2026-08-28 11:00:00'));

        // 6. Enclosed Overlap: 10:10-10:20 -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:10:00', '2026-08-28 10:20:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:10:00', '2026-08-28 10:20:00'));

        // 7. Adjacent Before: 09:30-10:00 -> Available (NO conflict)
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 09:30:00', '2026-08-28 10:00:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 09:30:00', '2026-08-28 10:00:00'));

        // 8. Adjacent After: 10:30-11:00 -> Available (NO conflict)
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:30:00', '2026-08-28 11:00:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:30:00', '2026-08-28 11:00:00'));

        // 9. Different Day: 29 Aug 10:00-10:30 -> Available
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-29 10:00:00', '2026-08-29 10:30:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-29 10:00:00', '2026-08-29 10:30:00'));
    }

    /**
     * Fixed Screen: Cancelled and rejected bookings must not block inventory.
     */
    public function test_non_capacity_booking_statuses_do_not_block_fixed_inventory()
    {
        $screen = Screen::create([
            'name' => 'Kochi Center Screen',
            'screen_code' => 'FX-002',
            'screen_type' => 'Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Center',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Create cancelled booking
        $cancelledBooking = Booking::create([
            'title' => 'Cancelled Campaign',
            'advertiser_name' => 'Test',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'status' => 'cancelled',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $cancelledBooking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'subtotal' => 500.00,
            'quantity' => 1,
            'status' => 'cancelled',
        ]);

        // Screen must be AVAILABLE despite cancelled booking in the same interval
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-08-28 10:00:00', '2026-08-28 10:30:00'));
    }

    /**
     * Mobile Fleet: Test non-overlapping intraday intervals do NOT falsely sum together.
     */
    public function test_mobile_fleet_non_overlapping_intraday_bookings_do_not_sum()
    {
        // Mobile asset with 100 units
        $fleet = Screen::create([
            'name' => 'Kochi Mobile LED Vans',
            'screen_code' => 'MOB-001',
            'screen_type' => 'LED Van',
            'asset_type' => 'mobile_van',
            'fleet_size' => 100,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Kochi Fleet Base',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Booking A: 10:00 to 11:00 (60 units)
        $bookingA = Booking::create([
            'title' => 'Morning Fleet Run',
            'advertiser_name' => 'Brand A',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 11:00:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $bookingA->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'subtotal' => 6000.00,
            'quantity' => 60,
            'status' => 'approved',
        ]);

        // Booking B: 14:00 to 15:00 (50 units) (Non-overlapping)
        $bookingB = Booking::create([
            'title' => 'Afternoon Fleet Run',
            'advertiser_name' => 'Brand B',
            'start_time' => '2026-08-28 14:00:00',
            'end_time' => '2026-08-28 15:00:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $bookingB->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'subtotal' => 5000.00,
            'quantity' => 50,
            'status' => 'approved',
        ]);

        // During 10:00-11:00: 40 units available (100 - 60)
        $availMorning = $this->inventoryService->getAvailableFleet($fleet->id, '2026-08-28 10:00:00', '2026-08-28 11:00:00');
        $this->assertEquals(40, $availMorning);

        // During 14:00-15:00: 50 units available (100 - 50)
        $availAfternoon = $this->inventoryService->getAvailableFleet($fleet->id, '2026-08-28 14:00:00', '2026-08-28 15:00:00');
        $this->assertEquals(50, $availAfternoon);

        // During 11:30-13:30 (quiet window between runs): Full 100 units available
        $availMidday = $this->inventoryService->getAvailableFleet($fleet->id, '2026-08-28 11:30:00', '2026-08-28 13:30:00');
        $this->assertEquals(100, $availMidday);

        // Validating 50 units during 14:00-15:00 succeeds (not blocked by morning 60 units)
        $conflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $fleet->id, 'quantity' => 50]
        ], '2026-08-28 14:00:00', '2026-08-28 15:00:00');
        $this->assertEmpty($conflicts);
    }

    /**
     * Mobile Fleet: Test overlapping intervals properly detect concurrent capacity breaches.
     */
    public function test_mobile_fleet_overlapping_intervals_trigger_capacity_conflict()
    {
        // Mobile asset with 100 units
        $fleet = Screen::create([
            'name' => 'Bangalore Fleet',
            'screen_code' => 'MOB-002',
            'screen_type' => 'LED Van',
            'asset_type' => 'mobile_van',
            'fleet_size' => 100,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Bangalore Hub',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Booking A: 10:00 to 11:00 (60 units)
        $bookingA = Booking::create([
            'title' => 'Campaign A',
            'advertiser_name' => 'Brand A',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 11:00:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $bookingA->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'subtotal' => 6000.00,
            'quantity' => 60,
            'status' => 'approved',
        ]);

        // Request 1: 10:30 to 11:30 requesting 50 units (Overlaps 10:30-11:00 with Booking A -> Concurrent peak 60+50 = 110 > 100)
        // Available units during 10:30-11:30 is only 40 (100 - 60)
        $avail = $this->inventoryService->getAvailableFleet($fleet->id, '2026-08-28 10:30:00', '2026-08-28 11:30:00');
        $this->assertEquals(40, $avail);

        $conflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $fleet->id, 'quantity' => 50]
        ], '2026-08-28 10:30:00', '2026-08-28 11:30:00');
        $this->assertNotEmpty($conflicts);
        $this->assertStringContainsString('only 40 units available', $conflicts[0]);

        // Request 2: 10:30 to 11:30 requesting exactly 40 units (Concurrent peak 60+40 = 100 <= 100) -> Allowed!
        $validConflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $fleet->id, 'quantity' => 40]
        ], '2026-08-28 10:30:00', '2026-08-28 11:30:00');
        $this->assertEmpty($validConflicts);
    }

    /**
     * Mobile Fleet: Adjacent booking intervals on mobile fleet do NOT produce false spike.
     */
    public function test_mobile_fleet_adjacent_intervals_do_not_produce_false_spikes()
    {
        $fleet = Screen::create([
            'name' => 'Delhi Fleet',
            'screen_code' => 'MOB-003',
            'screen_type' => 'LED Van',
            'asset_type' => 'mobile_van',
            'fleet_size' => 100,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Delhi Base',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Booking A: 10:00 to 11:00 (60 units)
        $bookingA = Booking::create([
            'title' => 'Shift 1',
            'advertiser_name' => 'Brand A',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 11:00:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $bookingA->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'subtotal' => 6000.00,
            'quantity' => 60,
            'status' => 'approved',
        ]);

        // Adjacent Shift 2: Exactly 11:00 to 12:00 requesting 80 units
        // Since Shift 1 ends at 11:00, full 100 units are available from 11:00 onward
        $avail = $this->inventoryService->getAvailableFleet($fleet->id, '2026-08-28 11:00:00', '2026-08-28 12:00:00');
        $this->assertEquals(100, $avail);

        $conflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $fleet->id, 'quantity' => 80]
        ], '2026-08-28 11:00:00', '2026-08-28 12:00:00');
        $this->assertEmpty($conflicts);
    }

    /**
     * Test AvailabilityEngineController API endpoint with timestamp parameters.
     */
    public function test_availability_engine_api_endpoint()
    {
        $fleet = Screen::create([
            'name' => 'API Test Fleet',
            'screen_code' => 'MOB-API',
            'screen_type' => 'LED Van',
            'asset_type' => 'mobile_van',
            'fleet_size' => 50,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Hub',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $response = $this->postJson('/api/v2/availability/check', [
            'items' => [
                ['asset_id' => $fleet->id, 'quantity' => 30]
            ],
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 12:00:00',
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'available' => true,
            'conflicts' => [],
        ]);
    }

    /**
     * Test boundary intervals and precise minute edges.
     */
    public function test_boundary_interval_precision()
    {
        $screen = Screen::create([
            'name' => 'Edge Screen',
            'screen_code' => 'FX-EDGE',
            'screen_type' => 'Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Edge St',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Existing: 10:00:00 to 10:30:00
        $booking = Booking::create([
            'title' => 'Edge Booking',
            'advertiser_name' => 'Edge Corp',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'subtotal' => 500.00,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // 09:59 to 10:00 -> Available (touches boundary at 10:00)
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 09:59:00', '2026-08-28 10:00:00'));

        // 10:30 to 10:31 -> Available (touches boundary at 10:30)
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:30:00', '2026-08-28 10:31:00'));

        // 10:29 to 10:31 -> Overlaps by 1 minute (10:29-10:30) -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 10:29:00', '2026-08-28 10:31:00'));

        // 11:00 to 12:00 -> Far after -> Available
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 11:00:00', '2026-08-28 12:00:00'));
    }

    /**
     * Test cross-day intervals (e.g. 23:30 to 00:30 next day).
     */
    public function test_cross_day_intervals_detect_conflicts_correctly()
    {
        $screen = Screen::create([
            'name' => 'Midnight Screen',
            'screen_code' => 'FX-MIDNIGHT',
            'screen_type' => 'Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Night Square',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Existing: 28 Aug 23:30 to 29 Aug 00:30
        $booking = Booking::create([
            'title' => 'Late Night Run',
            'advertiser_name' => 'Night Owl',
            'start_time' => '2026-08-28 23:30:00',
            'end_time' => '2026-08-29 00:30:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'subtotal' => 1000.00,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Request: 28 Aug 23:45 to 29 Aug 00:15 -> Overlaps -> Conflict
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 23:45:00', '2026-08-29 00:15:00'));

        // Request: 29 Aug 00:30 to 29 Aug 01:30 -> Adjacent -> Available
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-29 00:30:00', '2026-08-29 01:30:00'));
    }

    /**
     * Test POST /api/bookings/check-conflicts endpoint with mobile and fixed screens.
     */
    public function test_booking_check_conflicts_endpoint()
    {
        $fleet = Screen::create([
            'name' => 'Conflict Check Fleet',
            'screen_code' => 'MOB-CHK',
            'screen_type' => 'LED Van',
            'asset_type' => 'mobile_van',
            'fleet_size' => 10,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Hub',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // Fully book the fleet: 10 units for 10:00-11:00
        $booking = Booking::create([
            'title' => 'Full Booking',
            'advertiser_name' => 'Full Corp',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 11:00:00',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'subtotal' => 1000.00,
            'quantity' => 10,
            'status' => 'approved',
        ]);

        // Check conflicts during 10:15 to 10:45 -> Has conflict (0 capacity left)
        $response = $this->actingAs($user)->postJson('/api/bookings/check-conflicts', [
            'screen_id' => $fleet->id,
            'start_time' => '2026-08-28 10:15:00',
            'end_time' => '2026-08-28 10:45:00',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'has_available_capacity' => false,
            ]
        ]);

        // Check conflicts during 11:00 to 12:00 -> Available
        $response2 = $this->actingAs($user)->postJson('/api/bookings/check-conflicts', [
            'screen_id' => $fleet->id,
            'start_time' => '2026-08-28 11:00:00',
            'end_time' => '2026-08-28 12:00:00',
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'data' => [
                'has_available_capacity' => true,
            ]
        ]);
    }

    /**
     * Test invalid intervals are rejected.
     */
    public function test_invalid_interval_is_rejected()
    {
        $screen = Screen::create([
            'name' => 'Test Screen',
            'screen_code' => 'FX-INV',
            'screen_type' => 'Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Hub',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Inverted interval: 11:00 to 10:00
        $this->assertEquals(0, $this->inventoryService->getAvailableFleet($screen->id, '2026-08-28 11:00:00', '2026-08-28 10:00:00'));

        $conflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $screen->id, 'quantity' => 1]
        ], '2026-08-28 11:00:00', '2026-08-28 10:00:00');
        $this->assertNotEmpty($conflicts);
        $this->assertEquals('End time must be after start time.', $conflicts[0]);
    }

    /**
     * Test fixed screen multi-booking capacity up to max_ads_per_day (e.g. 12 concurrent ads).
     */
    public function test_fixed_screen_multi_booking_capacity_up_to_max_ads_per_day()
    {
        Setting::setGroup('booking', ['max_ads_per_day' => 12]);

        $dailyPlan = PricingPlan::create([
            'name' => 'Daily Plan',
            'base_price' => 5000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Day',
            'is_active' => true,
        ]);

        $screen = Screen::create([
            'name' => 'LED WALL',
            'screen_code' => 'LED-001',
            'screen_type' => 'Digital Screen',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'City Center',
            'pricing_plan_id' => $dailyPlan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $user = User::factory()->create();

        // 1. Initially no conflicts
        $conflicts = $this->capacityService->validateScreenDailyCapacity([$screen->id], '2026-08-28', '2026-08-28');
        $this->assertEmpty($conflicts);

        // 2. Create 1 existing active booking on LED WALL
        $booking1 = Booking::create([
            'title' => 'First Ad Campaign',
            'advertiser_name' => 'Brand A',
            'start_time' => '2026-08-28 00:00:00',
            'end_time' => '2026-08-28 23:59:59',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking1->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'subtotal' => 500.00,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Screen MUST still have capacity (1 booking < 12 max_ads_per_day)
        $conflicts = $this->capacityService->validateScreenDailyCapacity([$screen->id], '2026-08-28', '2026-08-28');
        $this->assertEmpty($conflicts);

        // 3. Add 11 more bookings to reach capacity (total 12)
        for ($i = 2; $i <= 12; $i++) {
            $b = Booking::create([
                'title' => "Campaign {$i}",
                'advertiser_name' => "Brand {$i}",
                'start_time' => '2026-08-28 00:00:00',
                'end_time' => '2026-08-28 23:59:59',
                'status' => 'approved',
                'user_id' => $user->id,
            ]);
            BookingScreen::create([
                'booking_id' => $b->id,
                'screen_id' => $screen->id,
                'city_id' => $this->city->id,
                'subtotal' => 500.00,
                'quantity' => 1,
                'status' => 'approved',
            ]);
        }

        // Now screen is at capacity (12 >= 12, conflict returned)
        $conflicts = $this->capacityService->validateScreenDailyCapacity([$screen->id], '2026-08-28', '2026-08-28');
        $this->assertNotEmpty($conflicts);
        $this->assertEquals(12, $conflicts[0]['current_ads']);
        $this->assertEquals(12, $conflicts[0]['max_ads']);
    }
}
