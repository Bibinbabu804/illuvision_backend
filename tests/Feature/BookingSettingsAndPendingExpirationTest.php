<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingActivity;
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
use App\Support\Configs\BookingConfig;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingSettingsAndPendingExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settingsService;
    protected BookingConfig $bookingConfig;
    protected BookingCapacityService $capacityService;
    protected InventoryService $inventoryService;
    protected User $admin;
    protected Country $country;
    protected City $city;
    protected PricingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = app(SettingsService::class);
        $this->bookingConfig = app(BookingConfig::class);
        $this->capacityService = app(BookingCapacityService::class);
        $this->inventoryService = app(InventoryService::class);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India', 'is_active' => true]);
        $this->city = City::create(['country_id' => $this->country->id, 'name' => 'Kochi', 'is_active' => true]);
        $this->plan = PricingPlan::create([
            'name' => 'Hourly Plan',
            'base_price' => 500.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@illuvision.net',
        ]);
    }

    /**
     * 1. Setting default test: Missing setting resolves to 12.
     */
    public function test_setting_defaults_to_12_without_database_entry()
    {
        // Ensure no setting exists in DB
        Setting::where('group', 'booking')->where('key', 'max_ads_per_day')->delete();
        $this->settingsService->clearCache();

        $this->assertEquals(12, $this->bookingConfig->maxAdsPerDay());
        $this->assertEquals(12, $this->settingsService->getInt('booking', 'max_ads_per_day', 12));

        $response = $this->actingAs($this->admin)->getJson('/api/settings/booking');
        $response->assertStatus(200)
            ->assertJsonPath('data.max_ads_per_day', 12);
    }

    /**
     * 2. Setting mutation and cache invalidation test: 12 -> 15 -> 20 -> 12.
     */
    public function test_setting_mutation_and_cache_propagation()
    {
        // Initial
        $this->assertEquals(12, $this->bookingConfig->maxAdsPerDay());

        // Update to 15 via API
        $response = $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 15,
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('data.max_ads_per_day', 15);

        // Verify config reads 15
        $this->assertEquals(15, $this->bookingConfig->maxAdsPerDay());

        // Update to 20 via API
        $response2 = $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 20,
        ]);
        $response2->assertStatus(200)
            ->assertJsonPath('data.max_ads_per_day', 20);

        $this->assertEquals(20, $this->bookingConfig->maxAdsPerDay());

        // Update back to 12
        $response3 = $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 12,
        ]);
        $response3->assertStatus(200)
            ->assertJsonPath('data.max_ads_per_day', 12);

        $this->assertEquals(12, $this->bookingConfig->maxAdsPerDay());
    }

    /**
     * 3. Setting validation test: Accepts positive integers, rejects 0, negatives, decimals, strings.
     */
    public function test_setting_validation_rejects_invalid_values()
    {
        // Reject 0
        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_ads_per_day']);

        // Reject negative (-1)
        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_ads_per_day']);

        // Reject decimal (12.5)
        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 12.5,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_ads_per_day']);

        // Reject string ("abc")
        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors(['max_ads_per_day']);

        // Accept valid (1, 100)
        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 1,
        ])->assertStatus(200);

        $this->actingAs($this->admin)->putJson('/api/settings/booking', [
            'max_ads_per_day' => 100,
        ])->assertStatus(200);
    }

    /**
     * 4. Expiration under TTL: Booking created 10m ago remains payment_pending and holds capacity.
     */
    public function test_payment_pending_under_ttl_is_not_expired()
    {
        $screen = Screen::create([
            'name' => 'LED Wall A',
            'screen_code' => 'LED-001',
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

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Recent Pending Booking',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-01 10:00:00',
            'end_time' => '2026-10-01 12:00:00',
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subMinutes(10),
            'updated_at' => Carbon::now()->subMinutes(10),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        // Run expiration command
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        $booking->refresh();
        $this->assertEquals(Booking::STATUS_PAYMENT_PENDING, $booking->status);

        // Capacity is still held
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-10-01 10:00:00', '2026-10-01 12:00:00'));
    }

    /**
     * 5. Expiration over TTL: Booking created 31m ago transitions to expired and releases capacity.
     */
    public function test_payment_pending_over_ttl_is_expired_and_releases_capacity()
    {
        $screen = Screen::create([
            'name' => 'LED Wall B',
            'screen_code' => 'LED-002',
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

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Old Pending Booking',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-01 10:00:00',
            'end_time' => '2026-10-01 12:00:00',
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subMinutes(31),
            'updated_at' => Carbon::now()->subMinutes(31),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        // Verify capacity is held before expiration
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-10-01 10:00:00', '2026-10-01 12:00:00'));

        // Run expiration command
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        $booking->refresh();
        $this->assertEquals(Booking::STATUS_EXPIRED, $booking->status);

        // Verify activity log was created
        $activity = BookingActivity::where('booking_id', $booking->id)->where('type', 'expired')->first();
        $this->assertNotNull($activity);
        $this->assertEquals('Booking Expired', $activity->label);

        // Verify capacity is released
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($screen->id, '2026-10-01 10:00:00', '2026-10-01 12:00:00'));
        $this->assertEquals(1, $this->inventoryService->getAvailableFleet($screen->id, '2026-10-01 10:00:00', '2026-10-01 12:00:00'));
    }

    /**
     * 6. Preserves confirmed, approved, cancelled, completed bookings over 30m.
     */
    public function test_non_pending_bookings_are_never_expired()
    {
        $screen = Screen::create([
            'name' => 'LED Wall C',
            'screen_code' => 'LED-003',
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

        $statuses = [
            Booking::STATUS_PENDING_APPROVAL,
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
            Booking::STATUS_CANCELLED,
            Booking::STATUS_PAYMENT_FAILED,
        ];

        $createdBookings = [];
        foreach ($statuses as $status) {
            $createdBookings[$status] = Booking::withoutGlobalScopes()->create([
                'user_id' => $this->admin->id,
                'title' => "Booking Status {$status}",
                'advertiser_name' => 'Advertiser',
                'start_time' => '2026-10-01 10:00:00',
                'end_time' => '2026-10-01 12:00:00',
                'status' => $status,
            ]);

            DB::table('bookings')->where('id', $createdBookings[$status]->id)->update([
                'created_at' => Carbon::now()->subMinutes(60),
                'updated_at' => Carbon::now()->subMinutes(60),
            ]);
        }

        // Run expiration command
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        foreach ($statuses as $status) {
            $b = $createdBookings[$status]->fresh();
            $this->assertEquals($status, $b->status, "Booking with status {$status} should not have been altered.");
        }
    }

    /**
     * 7. Mobile Fleet capacity release upon expiration.
     */
    public function test_mobile_fleet_capacity_released_on_expiration()
    {
        $mobileScreen = Screen::create([
            'name' => 'Transit Van Fleet',
            'screen_code' => 'MB-001',
            'screen_type' => 'Transit Van',
            'asset_type' => 'mobile',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Kochi Metro',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'is_bookable' => true,
            'is_mobile' => true,
            'fleet_size' => 10,
        ]);

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Mobile Fleet Booking',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-11-01 00:00:00',
            'end_time' => '2026-11-05 23:59:59',
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subMinutes(40),
            'updated_at' => Carbon::now()->subMinutes(40),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $mobileScreen->id,
            'city_id' => $this->city->id,
            'quantity' => 7,
            'status' => 'pending',
        ]);

        // Available fleet before expiration: 10 - 7 = 3
        $this->assertEquals(3, $this->inventoryService->getAvailableFleet($mobileScreen->id, '2026-11-01', '2026-11-05'));

        // Run expiration
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        $booking->refresh();
        $this->assertEquals(Booking::STATUS_EXPIRED, $booking->status);

        // Available fleet after expiration: 10 - 0 = 10
        $this->assertEquals(10, $this->inventoryService->getAvailableFleet($mobileScreen->id, '2026-11-01', '2026-11-05'));
    }

    /**
     * 8. Long-range booking expiration: Solves the reported Screen 3911 scenario.
     */
    public function test_long_range_payment_pending_booking_expires_and_unblocks_screen()
    {
        $screen = Screen::create([
            'name' => 'LED Wall 3911 Replica',
            'screen_code' => 'AST-357-TEST',
            'screen_type' => 'LED Wall',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Muvattupuzha',
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'is_bookable' => true,
            'is_mobile' => false,
            'fleet_size' => 1,
        ]);

        // 6-month booking in payment_pending
        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => '6-Month Abandoned Campaign',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-08-16 00:00:00',
            'end_time' => '2027-02-11 00:00:00',
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subHours(2),
            'updated_at' => Carbon::now()->subHours(2),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        // Screen is blocked across the 6-month range
        $conflicts = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $screen->id, 'quantity' => 1],
        ], '2026-08-20', '2026-08-25');
        $this->assertNotEmpty($conflicts);

        // Run expiration
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        $booking->refresh();
        $this->assertEquals(Booking::STATUS_EXPIRED, $booking->status);

        // Screen is now unblocked
        $conflictsAfter = $this->inventoryService->validateFleetAvailability([
            ['asset_id' => $screen->id, 'quantity' => 1],
        ], '2026-08-20', '2026-08-25');
        $this->assertEmpty($conflictsAfter);
    }

    /**
     * 9. Idempotency test: Multiple command executions produce identical safe state.
     */
    public function test_expiration_command_is_idempotent()
    {
        $screen = Screen::create([
            'name' => 'LED Wall D',
            'screen_code' => 'LED-004',
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

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Expired Candidate',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-01 10:00:00',
            'end_time' => '2026-10-01 12:00:00',
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subMinutes(45),
            'updated_at' => Carbon::now()->subMinutes(45),
        ]);

        // Run 1
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);
        $this->assertEquals(Booking::STATUS_EXPIRED, $booking->fresh()->status);
        $this->assertEquals(1, BookingActivity::where('booking_id', $booking->id)->where('type', 'expired')->count());

        // Run 2
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);
        $this->assertEquals(Booking::STATUS_EXPIRED, $booking->fresh()->status);
        $this->assertEquals(1, BookingActivity::where('booking_id', $booking->id)->where('type', 'expired')->count());
    }
}
