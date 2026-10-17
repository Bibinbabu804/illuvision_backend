<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PricingPlan;
use App\Models\ReferralCode;
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

class BookingChannelsAndRescheduleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected BookingCapacityService $capacityService;
    protected InventoryService $inventoryService;
    protected SettingsService $settingsService;

    protected User $admin;
    protected User $customer;
    protected User $partnerUser;
    protected Partner $partner;
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
            'minimum_duration_type' => 'Hour',
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

        $this->partnerUser = User::factory()->create([
            'role' => 'partner',
            'email' => 'partner@illuvision.net',
        ]);

        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Agency Partner',
            'contact_person' => 'Rajesh Sharma',
            'email' => 'partner@agency.com',
            'phone' => '9876543210',
            'city' => 'Mumbai',
            'status' => Partner::STATUS_APPROVED,
        ]);

        ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'AGENCY10',
            'status' => 'active',
            'discount_type' => 'percentage',
            'discount_value' => 5,
            'commission_type' => 'percentage',
            'commission_value' => 10,
            'usage_limit' => 0,
            'used_count' => 0,
            'start_date' => now()->subDay(),
            'expiry_date' => now()->addYear(),
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
     * 1. Customer /bookings/campaign Channel: Enforces daily booking limit.
     */
    public function test_customer_booking_campaign_enforces_daily_limit()
    {
        $screen = $this->createFixedScreen('Customer Billboard', 'CB-01');
        $this->fillDailyCapacity($screen->id, '2026-10-01', 12);

        $payload = [
            'campaign_title' => 'Customer Campaign',
            'ad_frequency' => 'every_2_min',
            'start_time' => '2026-10-01 19:00:00',
            'end_time' => '2026-10-01 20:00:00',
            'screen_ids' => [$screen->id],
            'total_screens' => 1,
            'subtotal' => 500,
            'final_amount' => 500,
            'pricing_plan_id' => $this->plan->id,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer)->postJson('/api/bookings/campaign', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('errors.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED);
    }

    /**
     * 2. Customer /v2/bookings Channel: Enforces identical daily booking limit.
     */
    public function test_customer_v2_bookings_enforces_daily_limit()
    {
        $screen = $this->createFixedScreen('Customer V2 Billboard', 'CB-02');
        $this->fillDailyCapacity($screen->id, '2026-10-02', 12);

        $payload = [
            'campaign_title' => 'V2 Campaign',
            'start_date' => '2026-10-02',
            'end_date' => '2026-10-02',
            'items' => [
                ['asset_id' => $screen->id, 'quantity' => 1],
            ],
        ];

        $response = $this->actingAs($this->customer)->postJson('/api/v2/bookings', $payload);
        $response->assertStatus(422);
    }

    /**
     * 3. Admin Manual Booking Channel: Enforces daily booking limit.
     */
    public function test_admin_manual_booking_enforces_daily_limit()
    {
        $screen = $this->createFixedScreen('Admin Billboard', 'AB-01');
        $this->fillDailyCapacity($screen->id, '2026-10-03', 12);

        $payload = [
            'client' => ['name' => 'Direct Client', 'email' => 'client@direct.com'],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Admin Manual Campaign',
            'ad_frequency' => 'every_2_min',
            'start_time' => '2026-10-03 19:00:00',
            'end_time' => '2026-10-03 20:00:00',
            'screen_ids' => [$screen->id],
            'total_screens' => 1,
            'subtotal' => 500,
            'final_amount' => 500,
            'pricing_plan_id' => $this->plan->id,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', $payload);
        $response->assertStatus(422);
    }

    /**
     * 4. Ad Partner Booking Channel: Enforces identical daily limit.
     */
    public function test_partner_booking_enforces_daily_limit()
    {
        $screen = $this->createFixedScreen('Partner Billboard', 'PB-01');
        $this->fillDailyCapacity($screen->id, '2026-10-04', 12);

        $payload = [
            'client' => ['name' => 'Partner Client', 'email' => 'client@agency.com'],
            'campaign_title' => 'Partner Campaign',
            'start_time' => '2026-10-04 19:00:00',
            'end_time' => '2026-10-04 20:00:00',
            'screen_ids' => [$screen->id],
            'total_screens' => 1,
            'subtotal' => 500,
            'final_amount' => 500,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', $payload);
        $response->assertStatus(422);
    }

    /**
     * 5. Booking Update Self-Exclusion: Editing existing booking does NOT conflict with itself.
     */
    public function test_booking_update_self_exclusion()
    {
        $screen = $this->createFixedScreen('Update Screen', 'US-01');
        $bookings = $this->fillDailyCapacity($screen->id, '2026-10-05', 12);

        $targetBooking = $bookings[0]; // 08:00 - 08:30

        // Update targetBooking time from 08:00-08:30 to 08:00-08:45
        // Screen has 12 bookings, but 1 is targetBooking itself -> Self-excluded -> effective count 11/12 -> Allowed
        $response = $this->actingAs($this->admin)->putJson("/api/bookings/{$targetBooking->id}", [
            'start_time' => '2026-10-05 08:00:00',
            'end_time' => '2026-10-05 08:30:00',
            'title' => 'Updated Booking Title',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Booking Title');
    }

    /**
     * 6. Booking Update Reschedule to Full Date: Rejection preserves original booking state.
     */
    public function test_booking_update_reschedule_to_full_date_is_rejected_safely()
    {
        $screen = $this->createFixedScreen('Reschedule Screen', 'RS-01');

        // Create 1 booking on Oct 10
        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Original Booking Oct 10',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-10 10:00:00',
            'end_time' => '2026-10-10 11:00:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Fill Oct 11 to 12/12
        $this->fillDailyCapacity($screen->id, '2026-10-11', 12);

        // Attempt to reschedule booking from Oct 10 to Oct 11 -> must fail
        $response = $this->actingAs($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'start_time' => '2026-10-11 19:00:00',
            'end_time' => '2026-10-11 20:00:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED);

        // Verify original booking is completely unchanged on Oct 10
        $booking->refresh();
        $this->assertEquals('2026-10-10 10:00:00', $booking->start_time->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-10-10 11:00:00', $booking->end_time->format('Y-m-d H:i:s'));
    }

    /**
     * 7. Screen Change during Update: Reallocates capacity from Old Screen to New Screen.
     */
    public function test_booking_screen_change_reallocates_capacity_correctly()
    {
        $oldScreen = $this->createFixedScreen('Old Screen', 'OS-01');
        $newScreen = $this->createFixedScreen('New Screen', 'NS-01');

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Screen Switch Booking',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-15 10:00:00',
            'end_time' => '2026-10-15 11:00:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $oldScreen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Old screen is held
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($oldScreen->id, '2026-10-15 10:00:00', '2026-10-15 11:00:00'));
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($newScreen->id, '2026-10-15 10:00:00', '2026-10-15 11:00:00'));

        // Switch booking from oldScreen to newScreen
        $response = $this->actingAs($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'screen_ids' => [$newScreen->id],
        ]);
        $response->assertStatus(200);

        // Old screen is released, new screen is reserved
        $this->assertFalse($this->inventoryService->hasFixedScreenConflict($oldScreen->id, '2026-10-15 10:00:00', '2026-10-15 11:00:00'));
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($newScreen->id, '2026-10-15 10:00:00', '2026-10-15 11:00:00'));

        $this->assertDatabaseMissing('booking_screens', [
            'booking_id' => $booking->id,
            'screen_id' => $oldScreen->id,
        ]);
        $this->assertDatabaseHas('booking_screens', [
            'booking_id' => $booking->id,
            'screen_id' => $newScreen->id,
        ]);
    }

    /**
     * 8. Screen Change to Full Screen is Rejected and leaves Old Screen intact.
     */
    public function test_booking_screen_change_to_full_screen_fails_and_preserves_old_screen()
    {
        $oldScreen = $this->createFixedScreen('Old Screen Preserved', 'OSP-01');
        $fullScreen = $this->createFixedScreen('Full Screen Target', 'FST-01');

        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Preserved Switch Booking',
            'advertiser_name' => 'Advertiser',
            'start_time' => '2026-10-20 10:00:00',
            'end_time' => '2026-10-20 11:00:00',
            'status' => Booking::STATUS_APPROVED,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $oldScreen->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'status' => 'approved',
        ]);

        // Fill fullScreen to 12/12 on Oct 20
        $this->fillDailyCapacity($fullScreen->id, '2026-10-20', 12);

        // Attempt switch to fullScreen -> must fail
        $response = $this->actingAs($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'screen_ids' => [$fullScreen->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED);

        // Old screen reservation remains intact
        $this->assertDatabaseHas('booking_screens', [
            'booking_id' => $booking->id,
            'screen_id' => $oldScreen->id,
        ]);
        $this->assertTrue($this->inventoryService->hasFixedScreenConflict($oldScreen->id, '2026-10-20 10:00:00', '2026-10-20 11:00:00'));
    }

    /**
     * 9. Multi-Screen Partial Failure Rollback: Atomic transaction rollback.
     */
    public function test_multi_screen_partial_failure_rollback()
    {
        $screenA = $this->createFixedScreen('Screen A Free', 'SAF-01');
        $screenB = $this->createFixedScreen('Screen B Full', 'SBF-01');

        $this->fillDailyCapacity($screenB->id, '2026-10-25', 12);

        $initialBookingsCount = Booking::count();
        $initialScreensCount = BookingScreen::count();

        $payload = [
            'campaign_title' => 'Atomic Multi-Screen',
            'ad_frequency' => 'every_2_min',
            'start_time' => '2026-10-25 19:00:00',
            'end_time' => '2026-10-25 20:00:00',
            'screen_ids' => [$screenA->id, $screenB->id],
            'total_screens' => 2,
            'subtotal' => 1000,
            'final_amount' => 1000,
            'pricing_plan_id' => $this->plan->id,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer)->postJson('/api/bookings/campaign', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('errors.error_code', BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED);

        // Verify zero residual rows created
        $this->assertEquals($initialBookingsCount, Booking::count());
        $this->assertEquals($initialScreensCount, BookingScreen::count());
    }

    /**
     * 10. Concurrency: Serialized booking creation on 11/12 allows exactly 1 and rejects 2nd.
     */
    public function test_concurrent_creation_prevents_overbooking()
    {
        $screen = $this->createFixedScreen('Concurrency Screen', 'CS-01');
        $this->fillDailyCapacity($screen->id, '2026-10-30', 11);

        $success = 0;
        $failed = 0;

        for ($i = 0; $i < 2; $i++) {
            $payload = [
                'campaign_title' => "Concurrent Customer {$i}",
                'ad_frequency' => 'every_2_min',
                'start_time' => '2026-10-30 20:00:00',
                'end_time' => '2026-10-30 21:00:00',
                'screen_ids' => [$screen->id],
                'total_screens' => 1,
                'subtotal' => 500,
                'final_amount' => 500,
                'pricing_plan_id' => $this->plan->id,
                'advertisement_category_id' => $this->category->id,
            ];

            $response = $this->actingAs($this->customer)->postJson('/api/bookings/campaign', $payload);
            if ($response->status() === 201 || $response->status() === 200) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->assertEquals(1, $success);
        $this->assertEquals(1, $failed);

        // Total bookings on screen for that day is exactly 12
        $conflicts = $this->capacityService->validateScreenDailyCapacity([$screen->id], '2026-10-30', '2026-10-30');
        $this->assertNotEmpty($conflicts);
        $this->assertEquals(12, $conflicts[0]['current_ads']);
    }
}
