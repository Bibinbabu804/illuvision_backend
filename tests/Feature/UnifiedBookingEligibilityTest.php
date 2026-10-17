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
use App\Services\BookingCapacityService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use App\Support\BookingEligibilityResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedBookingEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected BookingCapacityService $capacityService;
    protected InventoryService $inventoryService;
    protected SettingsService $settingsService;
    protected User $admin;
    protected Country $country;
    protected City $city;
    protected PricingPlan $plan;

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

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@illuvision.net',
        ]);

        // Default max_ads_per_day to 12
        Setting::setGroup('booking', ['max_ads_per_day' => 12]);
        $this->settingsService->clearCache();
    }

    /**
     * Helper to create a fixed screen.
     */
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

    /**
     * Helper to create a mobile screen.
     */
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

    /**
     * Helper to create a booking on a screen.
     */
    protected function createBooking(
        int $screenId,
        string $startTime,
        string $endTime,
        string $status = Booking::STATUS_APPROVED,
        int $quantity = 1
    ): Booking {
        $booking = Booking::withoutGlobalScopes()->create([
            'user_id' => $this->admin->id,
            'title' => 'Test Booking',
            'advertiser_name' => 'Test Advertiser',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => $status,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screenId,
            'city_id' => $this->city->id,
            'quantity' => $quantity,
            'status' => 'approved',
        ]);

        return $booking;
    }

    /**
     * 1. Test Daily Booking Limit (11/12 allowed, 12/12 rejected, 13th rejected).
     */
    public function test_daily_booking_quota_evaluation()
    {
        $screen = $this->createFixedScreen('Daily Quota Screen', 'DQ-01');

        // Create 11 non-overlapping 30-min bookings on 2026-09-10
        for ($i = 0; $i < 11; $i++) {
            $h = str_pad(8 + intdiv($i, 2), 2, '0', STR_PAD_LEFT);
            $m = ($i % 2 === 0) ? '00' : '30';
            $endM = ($i % 2 === 0) ? '30' : '00';
            $endH = ($i % 2 === 0) ? $h : str_pad((int)$h + 1, 2, '0', STR_PAD_LEFT);

            $this->createBooking(
                $screen->id,
                "2026-09-10 {$h}:{$m}:00",
                "2026-09-10 {$endH}:{$endM}:00"
            );
        }

        // 12th booking request (11/12 used) -> Eligible
        $res12 = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-10 18:00:00',
            '2026-09-10 18:30:00'
        );
        $this->assertTrue($res12->isEligible());
        $this->assertNull($res12->getErrorCode());

        // Create the 12th booking
        $this->createBooking($screen->id, '2026-09-10 18:00:00', '2026-09-10 18:30:00');

        // 13th booking request (12/12 used) -> Ineligible (daily limit exceeded)
        $res13 = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-10 19:00:00',
            '2026-09-10 19:30:00'
        );
        $this->assertFalse($res13->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $res13->getErrorCode());
        $this->assertEquals(BookingEligibilityResult::TYPE_DAILY_LIMIT, $res13->getConflictType());
        $this->assertContains('2026-09-10', $res13->getConflictDates());

        // Dynamic Setting Change: Update max_ads_per_day from 12 to 15
        Setting::setGroup('booking', ['max_ads_per_day' => 15]);
        $this->settingsService->clearCache();

        // 13th booking is now eligible under new 15 limit
        $res13After = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-10 19:00:00',
            '2026-09-10 19:30:00'
        );
        $this->assertTrue($res13After->isEligible());
    }

    /**
     * 2. Test Different Screens: Screen A at 12/12 is blocked, Screen B at 0/12 is allowed.
     */
    public function test_per_screen_capacity_independence()
    {
        $screenA = $this->createFixedScreen('Screen A', 'SC-A');
        $screenB = $this->createFixedScreen('Screen B', 'SC-B');

        // Fill Screen A with 12 bookings on 2026-09-15
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + intdiv($i, 2), 2, '0', STR_PAD_LEFT);
            $m = ($i % 2 === 0) ? '00' : '30';
            $endM = ($i % 2 === 0) ? '30' : '00';
            $endH = ($i % 2 === 0) ? $h : str_pad((int)$h + 1, 2, '0', STR_PAD_LEFT);

            $this->createBooking($screenA->id, "2026-09-15 {$h}:{$m}:00", "2026-09-15 {$endH}:{$endM}:00");
        }

        // Screen B is empty -> eligible
        $resB = $this->capacityService->checkEligibility(
            [$screenB->id],
            '2026-09-15 10:00:00',
            '2026-09-15 10:30:00'
        );
        $this->assertTrue($resB->isEligible());

        // Screen A is full -> ineligible
        $resA = $this->capacityService->checkEligibility(
            [$screenA->id],
            '2026-09-15 20:00:00',
            '2026-09-15 20:30:00'
        );
        $this->assertFalse($resA->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $resA->getErrorCode());

        // Multi-screen request (A + B) -> must fail due to Screen A
        $resAB = $this->capacityService->checkEligibility(
            [$screenA->id, $screenB->id],
            '2026-09-15 20:00:00',
            '2026-09-15 20:30:00'
        );
        $this->assertFalse($resAB->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $resAB->getErrorCode());
    }

    /**
     * 3. Test Cancellation and Expiration release daily capacity.
     */
    public function test_cancellation_and_expiration_release_daily_capacity()
    {
        $screen = $this->createFixedScreen('Lifecycle Screen', 'LC-01');

        $bookings = [];
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + intdiv($i, 2), 2, '0', STR_PAD_LEFT);
            $m = ($i % 2 === 0) ? '00' : '30';
            $endM = ($i % 2 === 0) ? '30' : '00';
            $endH = ($i % 2 === 0) ? $h : str_pad((int)$h + 1, 2, '0', STR_PAD_LEFT);

            $status = ($i === 11) ? Booking::STATUS_PAYMENT_PENDING : Booking::STATUS_APPROVED;
            $bookings[] = $this->createBooking($screen->id, "2026-09-20 {$h}:{$m}:00", "2026-09-20 {$endH}:{$endM}:00", $status);
        }

        // Screen is at 12/12 -> blocked
        $res = $this->capacityService->checkEligibility([$screen->id], '2026-09-20 18:00:00', '2026-09-20 18:30:00');
        $this->assertFalse($res->isEligible());

        // Cancel 1 booking (index 0)
        $bookings[0]->update(['status' => Booking::STATUS_CANCELLED]);

        // Screen is now at 11/12 -> eligible
        $resAfterCancel = $this->capacityService->checkEligibility([$screen->id], '2026-09-20 18:00:00', '2026-09-20 18:30:00');
        $this->assertTrue($resAfterCancel->isEligible());

        // Fill back to 12/12
        $this->createBooking($screen->id, '2026-09-20 18:00:00', '2026-09-20 18:30:00');
        $this->assertFalse($this->capacityService->checkEligibility([$screen->id], '2026-09-20 19:00:00', '2026-09-20 19:30:00')->isEligible());

        // Expire the payment_pending booking (index 11)
        DB::table('bookings')->where('id', $bookings[11]->id)->update([
            'created_at' => Carbon::now()->subMinutes(35),
            'updated_at' => Carbon::now()->subMinutes(35),
        ]);
        Artisan::call('bookings:expire-pending', ['--ttl' => 30]);

        $this->assertEquals(Booking::STATUS_EXPIRED, $bookings[11]->fresh()->status);

        // Capacity released again -> eligible
        $resAfterExpire = $this->capacityService->checkEligibility([$screen->id], '2026-09-20 19:00:00', '2026-09-20 19:30:00');
        $this->assertTrue($resAfterExpire->isEligible());
    }

    /**
     * 4. Test Multi-Day Booking Calendar Semantics (Aug 28 -> Aug 30 where Aug 29 is 12/12).
     */
    public function test_multi_day_booking_fails_if_any_single_day_is_full()
    {
        $screen = $this->createMobileScreen('Multi-Day Screen', 'MD-01', 100);

        // Aug 28: 11 bookings
        for ($i = 0; $i < 11; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $this->createBooking($screen->id, "2026-08-28 {$h}:00:00", "2026-08-28 {$h}:30:00");
        }

        // Aug 29: 12 bookings (FULL)
        $aug29Bookings = [];
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $aug29Bookings[] = $this->createBooking($screen->id, "2026-08-29 {$h}:00:00", "2026-08-29 {$h}:30:00");
        }

        // Aug 30: 10 bookings
        for ($i = 0; $i < 10; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $this->createBooking($screen->id, "2026-08-30 {$h}:00:00", "2026-08-30 {$h}:30:00");
        }

        // Request 3-day booking: Aug 28 to Aug 30
        $res = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-08-28 21:00:00',
            '2026-08-30 22:00:00'
        );

        $this->assertFalse($res->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $res->getErrorCode());
        $this->assertContains('2026-08-29', $res->getConflictDates());

        // Cancel 1 booking on Aug 29 (now 11/12)
        $aug29Bookings[0]->update(['status' => Booking::STATUS_CANCELLED]);

        // Now Aug 28=11, Aug 29=11, Aug 30=10 -> 3-day booking is eligible
        $resAfter = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-08-28 21:00:00',
            '2026-08-30 22:00:00'
        );
        $this->assertTrue($resAfter->isEligible());
    }

    /**
     * 5. Test Fixed Screen Physical Time Conflicts vs Adjacent Intervals.
     */
    public function test_fixed_screen_time_interval_conflicts()
    {
        $screen = $this->createFixedScreen('Interval Screen', 'INT-01');

        // Existing booking 10:00 to 10:30
        $this->createBooking($screen->id, '2026-09-25 10:00:00', '2026-09-25 10:30:00');

        // Overlapping request: 10:15 to 10:45 -> Time Conflict
        $overlapRes = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-25 10:15:00',
            '2026-09-25 10:45:00'
        );
        $this->assertFalse($overlapRes->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_TIME_CONFLICT, $overlapRes->getErrorCode());
        $this->assertEquals(BookingEligibilityResult::TYPE_TIME_CONFLICT, $overlapRes->getConflictType());

        // Adjacent request: 10:30 to 11:00 -> Allowed
        $adjacentRes = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-25 10:30:00',
            '2026-09-25 11:00:00'
        );
        $this->assertTrue($adjacentRes->isEligible());

        // Adjacent preceding request: 09:30 to 10:00 -> Allowed
        $adjacentPreceding = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-09-25 09:30:00',
            '2026-09-25 10:00:00'
        );
        $this->assertTrue($adjacentPreceding->isEligible());
    }

    /**
     * 6. Test Mobile Fleet Line-Sweep Algorithm & Overlap Evaluation.
     */
    public function test_mobile_fleet_capacity_and_intraday_line_sweep()
    {
        $mobileScreen = $this->createMobileScreen('City Transit Fleet', 'CT-01', 100);

        // Booking 1: 10:00 - 11:00 (60 units)
        $this->createBooking($mobileScreen->id, '2026-10-05 10:00:00', '2026-10-05 11:00:00', Booking::STATUS_APPROVED, 60);

        // Booking 2: 14:00 - 15:00 (50 units - non-overlapping intraday)
        $this->createBooking($mobileScreen->id, '2026-10-05 14:00:00', '2026-10-05 15:00:00', Booking::STATUS_APPROVED, 50);

        // Request 50 units at 14:00 - 15:00 (50 existing + 50 requested = 100 <= 100) -> Allowed
        $resNonOverlap = $this->capacityService->checkEligibility(
            [['screen_id' => $mobileScreen->id, 'quantity' => 50]],
            '2026-10-05 14:00:00',
            '2026-10-05 15:00:00'
        );
        $this->assertTrue($resNonOverlap->isEligible());

        // Request 50 units overlapping with Booking 1: 10:30 - 11:30 (60 existing + 50 requested = 110 > 100) -> Fleet Exceeded
        $resOverlap = $this->capacityService->checkEligibility(
            [['screen_id' => $mobileScreen->id, 'quantity' => 50]],
            '2026-10-05 10:30:00',
            '2026-10-05 11:30:00'
        );
        $this->assertFalse($resOverlap->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_FLEET_CAPACITY_EXCEEDED, $resOverlap->getErrorCode());
        $this->assertEquals(BookingEligibilityResult::TYPE_FLEET_CAPACITY, $resOverlap->getConflictType());
    }

    /**
     * 7. Test Mobile Screen Daily Limit when Fleet is available.
     */
    public function test_mobile_screen_daily_quota_independent_of_fleet()
    {
        $mobileScreen = $this->createMobileScreen('Mobile Quota Screen', 'MQ-01', 500);

        // Create 12 small bookings (1 unit each) on 2026-10-10
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $this->createBooking($mobileScreen->id, "2026-10-10 {$h}:00:00", "2026-10-10 {$h}:30:00", Booking::STATUS_APPROVED, 1);
        }

        // Fleet has 499 units free, but daily limit is 12/12
        $res = $this->capacityService->checkEligibility(
            [['screen_id' => $mobileScreen->id, 'quantity' => 5]],
            '2026-10-10 20:00:00',
            '2026-10-10 20:30:00'
        );

        $this->assertFalse($res->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $res->getErrorCode());
    }

    /**
     * 8. Test Self-Exclusion during Booking Updates.
     */
    public function test_self_exclusion_during_booking_update()
    {
        $screen = $this->createFixedScreen('Self-Exclusion Screen', 'SE-01');

        // Create 12 bookings on 2026-10-15 (screen is full)
        $bookings = [];
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $bookings[] = $this->createBooking($screen->id, "2026-10-15 {$h}:00:00", "2026-10-15 {$h}:30:00");
        }

        $myBooking = $bookings[5]; // 13:00 - 13:30

        // Attempting to check without excludeBookingId -> 12/12 full -> ineligible
        $resWithoutExclude = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-10-15 13:00:00',
            '2026-10-15 13:30:00'
        );
        $this->assertFalse($resWithoutExclude->isEligible());

        // Updating myBooking from 13:00-13:30 to 20:00-20:30 with excludeBookingId -> eligible (effective count 11/12)
        $resWithExclude = $this->capacityService->checkEligibility(
            [$screen->id],
            '2026-10-15 20:00:00',
            '2026-10-15 20:30:00',
            $myBooking->id
        );
        $this->assertTrue($resWithExclude->isEligible());
    }

    /**
     * 9. Test Multi-Screen Mixed Asset Types and Atomic Rollback.
     */
    public function test_multi_screen_mixed_asset_types_and_atomicity()
    {
        $fixedA = $this->createFixedScreen('Screen Alpha', 'SC-ALP');
        $mobileB = $this->createMobileScreen('Van Beta', 'VN-BET', 10);
        $fixedC = $this->createFixedScreen('Screen Gamma', 'SC-GAM');

        // Fill fixedC with 12 bookings on 2026-11-01
        for ($i = 0; $i < 12; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $this->createBooking($fixedC->id, "2026-11-01 {$h}:00:00", "2026-11-01 {$h}:30:00");
        }

        // Request Alpha + Beta (both free) -> Eligible
        $resAB = $this->capacityService->checkEligibility(
            [
                ['screen_id' => $fixedA->id, 'quantity' => 1],
                ['screen_id' => $mobileB->id, 'quantity' => 4],
            ],
            '2026-11-01 10:00:00',
            '2026-11-01 11:00:00'
        );
        $this->assertTrue($resAB->isEligible());

        // Request Alpha + Beta + Gamma (Gamma is full) -> Ineligible
        $resABC = $this->capacityService->checkEligibility(
            [
                ['screen_id' => $fixedA->id, 'quantity' => 1],
                ['screen_id' => $mobileB->id, 'quantity' => 4],
                ['screen_id' => $fixedC->id, 'quantity' => 1],
            ],
            '2026-11-01 10:00:00',
            '2026-11-01 11:00:00'
        );
        $this->assertFalse($resABC->isEligible());
        $this->assertEquals(BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED, $resABC->getErrorCode());

        // Verify multi-screen transactional rollback in a simulated booking attempt
        $initialScreensCount = BookingScreen::count();
        $initialBookingsCount = Booking::count();

        try {
            DB::transaction(function () use ($fixedA, $mobileB, $fixedC) {
                $this->inventoryService->lockScreensForBooking([$fixedA->id, $mobileB->id, $fixedC->id]);

                $eligibility = $this->capacityService->checkEligibility(
                    [$fixedA->id, $mobileB->id, $fixedC->id],
                    '2026-11-01 10:00:00',
                    '2026-11-01 11:00:00'
                );

                if (!$eligibility->isEligible()) {
                    throw new \RuntimeException($eligibility->getFirstErrorMessage(), 422);
                }

                // Should not reach here
                BookingScreen::create([
                    'booking_id' => 99999,
                    'screen_id' => $fixedA->id,
                    'city_id' => $this->city->id,
                    'quantity' => 1,
                    'status' => 'pending',
                ]);
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Verify atomic rollback: 0 residual records created
        $this->assertEquals($initialScreensCount, BookingScreen::count());
        $this->assertEquals($initialBookingsCount, Booking::count());
    }

    /**
     * 10. Test Concurrency: Screen row locking ensures daily limit is never breached.
     */
    public function test_concurrent_booking_reservation_integrity()
    {
        $screen = $this->createFixedScreen('Concurrent Screen', 'CC-01');

        // Setup 11 existing bookings (1 slot remaining out of 12)
        for ($i = 0; $i < 11; $i++) {
            $h = str_pad(8 + $i, 2, '0', STR_PAD_LEFT);
            $this->createBooking($screen->id, "2026-11-10 {$h}:00:00", "2026-11-10 {$h}:30:00");
        }

        $successCount = 0;
        $rejectedCount = 0;

        // Attempt 2 consecutive booking transactions representing concurrent requests serialized by lockScreensForBooking
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                DB::transaction(function () use ($screen, $attempt, &$successCount) {
                    $this->inventoryService->lockScreensForBooking([$screen->id]);

                    $eligibility = $this->capacityService->checkEligibility(
                        [$screen->id],
                        '2026-11-10 19:30:00',
                        '2026-11-10 20:00:00'
                    );

                    if (!$eligibility->isEligible()) {
                        throw new \RuntimeException($eligibility->getFirstErrorMessage(), 422);
                    }

                    $booking = Booking::withoutGlobalScopes()->create([
                        'user_id' => $this->admin->id,
                        'title' => "Concurrent Booking {$attempt}",
                        'advertiser_name' => 'Advertiser',
                        'start_time' => '2026-11-10 19:30:00',
                        'end_time' => '2026-11-10 20:00:00',
                        'status' => Booking::STATUS_APPROVED,
                    ]);

                    BookingScreen::create([
                        'booking_id' => $booking->id,
                        'screen_id' => $screen->id,
                        'city_id' => $this->city->id,
                        'quantity' => 1,
                        'status' => 'approved',
                    ]);

                    $successCount++;
                });
            } catch (\RuntimeException $e) {
                $rejectedCount++;
            }
        }

        // Exactly 1 succeeded, 1 rejected
        $this->assertEquals(1, $successCount);
        $this->assertEquals(1, $rejectedCount);

        // Final active bookings count on the screen for 2026-11-10 is exactly 12 (never 13)
        $conflicts = $this->capacityService->validateScreenDailyCapacity([$screen->id], '2026-11-10', '2026-11-10');
        $this->assertNotEmpty($conflicts);
        $this->assertEquals(12, $conflicts[0]['current_ads']);
        $this->assertEquals(12, $conflicts[0]['max_ads']);
    }
}
