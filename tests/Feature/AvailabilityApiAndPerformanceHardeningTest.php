<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\User;
use App\Services\BookingCapacityService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use App\Support\BookingEligibilityResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailabilityApiAndPerformanceHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected BookingCapacityService $capacityService;
    protected InventoryService $inventoryService;
    protected SettingsService $settingsService;

    protected User $admin;
    protected User $customer;
    protected Country $country;
    protected City $city;
    protected PricingPlan $plan;
    protected AdvertisementCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capacityService = app(BookingCapacityService::class);
        $this->inventoryService = app(InventoryService::class);
        $this->settingsService = app(SettingsService::class);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India', 'is_active' => true]);
        $this->city = City::create(['country_id' => $this->country->id, 'name' => 'Kochi', 'is_active' => true]);
        $this->plan = PricingPlan::create([
            'name' => 'Standard Plan',
            'base_price' => 500.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Day',
            'is_active' => true,
        ]);

        $this->category = AdvertisementCategory::create([
            'name' => 'Commercial',
            'slug' => 'commercial',
            'status' => 'active',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@illuvision.net',
        ]);

        $this->customer = User::factory()->create([
            'role' => 'user',
            'email' => 'customer@illuvision.net',
        ]);

        Setting::setGroup('booking', ['max_ads_per_day' => 12]);
        $this->settingsService->clearCache();
    }

    protected function createFixedScreen(string $name = 'Fixed Screen', string $code = 'FX-01'): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'screen_type' => 'LED Wall',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'is_bookable' => true,
            'is_mobile' => false,
            'fleet_size' => 1,
        ]);
    }

    protected function createMobileScreen(string $name = 'Mobile Fleet', string $code = 'MB-01', int $fleetSize = 100): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'screen_type' => 'Transit Van',
            'asset_type' => 'mobile',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Kochi Metro',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'is_bookable' => true,
            'is_mobile' => true,
            'fleet_size' => $fleetSize,
        ]);
    }

    protected function fillDailyCapacity(int $screenId, string $date, int $count = 12): array
    {
        $bookings = [];
        for ($i = 0; $i < $count; $i++) {
            $h = str_pad(8 + intdiv($i, 2), 2, '0', STR_PAD_LEFT);
            $m = ($i % 2 === 0) ? '00' : '30';
            $endM = ($i % 2 === 0) ? '30' : '00';
            $endH = ($i % 2 === 0) ? $h : str_pad((int)$h + 1, 2, '0', STR_PAD_LEFT);

            $b = Booking::withoutGlobalScopes()->create([
                'user_id' => $this->admin->id,
                'title' => "Filler Booking {$i}",
                'advertiser_name' => 'Advertiser',
                'start_time' => "{$date} {$h}:{$m}:00",
                'end_time' => "{$date} {$endH}:{$endM}:00",
                'status' => Booking::STATUS_APPROVED,
            ]);

            BookingScreen::create([
                'booking_id' => $b->id,
                'screen_id' => $screenId,
                'city_id' => $this->city->id,
                'quantity' => 1,
                'status' => 'approved',
            ]);

            $bookings[] = $b;
        }

        return $bookings;
    }

    /**
     * 1. Advisory Nature: /v2/availability/check calculates eligibility without mutating database.
     */
    public function test_availability_check_is_advisory_and_creates_no_reservations()
    {
        $screen = $this->createFixedScreen('Advisory Screen', 'AS-01');

        $initialBookingsCount = Booking::count();
        $initialBookingScreensCount = BookingScreen::count();

        $response = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('available', true)
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('error_code', null)
            ->assertJsonPath('screens.0.screen_id', $screen->id)
            ->assertJsonPath('screens.0.eligible', true);

        $this->assertEquals($initialBookingsCount, Booking::count());
        $this->assertEquals($initialBookingScreensCount, BookingScreen::count());
    }

    /**
     * 2. Authoritative Same Input = Same Eligibility: Available becomes Unavailable upon booking.
     */
    public function test_same_input_availability_and_booking_eligibility_consistency()
    {
        $screen = $this->createFixedScreen('Consistency Screen 3911', 'CS-3911');
        $this->fillDailyCapacity($screen->id, '2026-11-05', 11);

        // Step A: 11/12 bookings -> Availability API reports available
        $availRes1 = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-05',
            'end_date' => '2026-11-05',
        ]);
        $availRes1->assertStatus(200)->assertJsonPath('available', true);

        // Step B: Customer creates 12th booking
        $bookingPayload = [
            'campaign_title' => 'The 12th Booking',
            'ad_frequency' => 'every_2_min',
            'start_time' => '2026-11-05 00:00:00',
            'end_time' => '2026-11-05 23:59:59',
            'screen_ids' => [$screen->id],
            'total_screens' => 1,
            'subtotal' => 500,
            'final_amount' => 500,
            'pricing_plan_id' => $this->plan->id,
            'advertisement_category_id' => $this->category->id,
        ];
        $bookRes = $this->actingAs($this->customer)->postJson('/api/bookings/campaign', $bookingPayload);
        $bookRes->assertStatus(201);

        // Step C: Availability API now reports unavailable with SCREEN_DAILY_CAPACITY_EXCEEDED
        $availRes2 = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-05',
            'end_date' => '2026-11-05',
        ]);
        $availRes2->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED)
            ->assertJsonPath('conflict_dates', ['2026-11-05'])
            ->assertJsonPath('details.maximum_bookings', 12)
            ->assertJsonPath('details.current_bookings', 12);

        // Step D: Booking creation also rejects with identical error code
        $bookRes2 = $this->actingAs($this->customer)->postJson('/api/bookings/campaign', [
            'campaign_title' => 'The 13th Booking Attempt',
            'ad_frequency' => 'every_2_min',
            'start_time' => '2026-11-05 00:00:00',
            'end_time' => '2026-11-05 23:59:59',
            'screen_ids' => [$screen->id],
            'total_screens' => 1,
            'subtotal' => 500,
            'final_amount' => 500,
            'pricing_plan_id' => $this->plan->id,
            'advertisement_category_id' => $this->category->id,
        ]);
        $bookRes2->assertStatus(422)
            ->assertJsonPath('errors.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED);
    }

    /**
     * 3. Multi-Day Conflict Date Isolation: Returns exact failed dates.
     */
    public function test_multi_day_availability_returns_exact_conflict_dates()
    {
        $screen = $this->createFixedScreen('Multi-Day Screen', 'MDS-01');

        // Fill Nov 11 to 12/12; Nov 10 has 0, Nov 12 has 0
        $this->fillDailyCapacity($screen->id, '2026-11-11', 12);

        $response = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-12',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED)
            ->assertJsonPath('conflict_dates', ['2026-11-11'])
            ->assertJsonPath('details.conflict_dates', ['2026-11-11']);
    }

    /**
     * 4. Multi-Screen Availability Breakdown: Identifies per-screen failure reasons.
     */
    public function test_multi_screen_availability_provides_granular_breakdown()
    {
        $screenA = $this->createFixedScreen('Screen A Free', 'MSA-01');
        $screenB = $this->createFixedScreen('Screen B Daily Full', 'MSB-01');
        $screenC = $this->createFixedScreen('Screen C Time Busy', 'MSC-01');

        // Screen B is daily full on Nov 15
        $this->fillDailyCapacity($screenB->id, '2026-11-15', 12);

        // Screen C has fixed time booking 10:00-11:00 on Nov 15
        $bC = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Fixed Time Slot',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-11-15 10:00:00',
            'end_time' => '2026-11-15 11:00:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $bC->id,
            'screen_id' => $screenC->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        $response = $this->postJson('/api/v2/availability/check', [
            'items' => [
                ['asset_id' => $screenA->id, 'quantity' => 1],
                ['asset_id' => $screenB->id, 'quantity' => 1],
                ['asset_id' => $screenC->id, 'quantity' => 1],
            ],
            'start_time' => '2026-11-15 10:15:00',
            'end_time' => '2026-11-15 10:45:00',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('screens.0.screen_id', $screenA->id)
            ->assertJsonPath('screens.0.eligible', true)
            ->assertJsonPath('screens.1.screen_id', $screenB->id)
            ->assertJsonPath('screens.1.eligible', false)
            ->assertJsonPath('screens.1.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED)
            ->assertJsonPath('screens.2.screen_id', $screenC->id)
            ->assertJsonPath('screens.2.eligible', false)
            ->assertJsonPath('screens.2.error_code', BookingEligibilityResult::CODE_TIME_CONFLICT);
    }

    /**
     * 5. Fixed Screen Timestamp Conflict and Adjacency: Precise minute intervals.
     */
    public function test_fixed_screen_timestamp_conflict_and_adjacent_slots()
    {
        $screen = $this->createFixedScreen('Interval Screen', 'IS-01');

        // Existing booking 10:00 - 10:30
        $b = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Slot 10:00-10:30',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-11-20 10:00:00',
            'end_time' => '2026-11-20 10:30:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $b->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Overlapping 10:15 - 10:45 -> Conflict
        $overlapRes = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_time' => '2026-11-20 10:15:00',
            'end_time' => '2026-11-20 10:45:00',
        ]);
        $overlapRes->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('error_code', BookingEligibilityResult::CODE_TIME_CONFLICT);

        // Adjacent 10:30 - 11:00 -> Allowed
        $adjacentRes = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_time' => '2026-11-20 10:30:00',
            'end_time' => '2026-11-20 11:00:00',
        ]);
        $adjacentRes->assertStatus(200)
            ->assertJsonPath('available', true)
            ->assertJsonPath('error_code', null);
    }

    /**
     * 6. Mobile Fleet Line-Sweep & Daily Limit: Evaluates both fleet size and daily limits.
     */
    public function test_mobile_fleet_availability_evaluates_fleet_size_and_daily_limit()
    {
        $fleet = $this->createMobileScreen('Mobile LineSweep', 'MLS-01', 100);

        // Existing booking 10:00-11:00 with quantity 60
        $b = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Fleet 60',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-11-25 10:00:00',
            'end_time' => '2026-11-25 11:00:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $b->id,
            'screen_id' => $fleet->id,
            'city_id' => $this->city->id,
            'quantity' => 60,
            'status' => 'approved',
        ]);

        // Request 30 units (100 - 60 = 40 free) -> Available
        $res1 = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $fleet->id, 'quantity' => 30]],
            'start_time' => '2026-11-25 10:15:00',
            'end_time' => '2026-11-25 10:45:00',
        ]);
        $res1->assertStatus(200)->assertJsonPath('available', true);

        // Request 50 units (only 40 free) -> Fleet capacity exceeded
        $res2 = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $fleet->id, 'quantity' => 50]],
            'start_time' => '2026-11-25 10:15:00',
            'end_time' => '2026-11-25 10:45:00',
        ]);
        $res2->assertStatus(200)
            ->assertJsonPath('available', false)
            ->assertJsonPath('error_code', BookingEligibilityResult::CODE_FLEET_CAPACITY_EXCEEDED)
            ->assertJsonPath('details.requested_quantity', 50)
            ->assertJsonPath('details.available_quantity', 40);
    }

    /**
     * 7. Cancellation and Expiration immediately release availability.
     */
    public function test_cancellation_and_expiration_release_availability_immediately()
    {
        $screen = $this->createFixedScreen('Release Screen', 'RLS-01');
        $bookings = $this->fillDailyCapacity($screen->id, '2026-11-30', 12);

        // 12/12 -> Unavailable
        $resBefore = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-30',
            'end_date' => '2026-11-30',
        ]);
        $resBefore->assertStatus(200)->assertJsonPath('available', false);

        // Cancel 1 booking
        $bookings[0]->update(['status' => Booking::STATUS_CANCELLED]);
        $bookings[0]->bookingScreens()->update(['status' => 'cancelled']);

        // Now 11/12 -> Available
        $resAfter = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-11-30',
            'end_date' => '2026-11-30',
        ]);
        $resAfter->assertStatus(200)->assertJsonPath('available', true);
    }

    /**
     * 8. Large Date Range Performance: 180 days evaluated efficiently in single batch query.
     */
    public function test_large_date_range_availability_performance()
    {
        $screen = $this->createFixedScreen('180-Day Screen', 'LDS-180');

        DB::enableQueryLog();

        $startTime = microtime(true);
        $response = $this->postJson('/api/v2/availability/check', [
            'items' => [['asset_id' => $screen->id, 'quantity' => 1]],
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30', // 180 days
        ]);
        $durationMs = (microtime(true) - $startTime) * 1000;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200)->assertJsonPath('available', true);

        // Query count is bounded (under 10 queries, NOT 180 queries!)
        $this->assertLessThan(10, count($queries), 'Query count must be bounded and not scale N=180.');
        $this->assertLessThan(500, $durationMs, '180-day availability check should complete well under 500ms.');
    }
}
