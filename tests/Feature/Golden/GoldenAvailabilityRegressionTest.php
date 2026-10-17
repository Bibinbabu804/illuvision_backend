<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Services\BookingCapacityService;
use App\Services\ConflictDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoldenAvailabilityRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected BookingCapacityService $capacityService;
    protected ConflictDetectionService $conflictService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        $this->capacityService = app(BookingCapacityService::class);
        $this->conflictService = app(ConflictDetectionService::class);
    }

    /**
     * Test 1: Available screen returns eligible = true
     */
    public function test_available_screen_returns_eligible(): void
    {
        $items = [
            ['screen_id' => $this->fixedScreenIlluvision->id, 'quantity' => 1],
        ];
        $start = Carbon::now()->addDays(5)->format('Y-m-d H:i:s');
        $end = Carbon::now()->addDays(7)->format('Y-m-d H:i:s');

        $result = $this->capacityService->checkEligibility($items, $start, $end);

        $this->assertTrue($result->isEligible());
        $this->assertTrue($result->isAvailable());
        $this->assertEmpty($result->getConflictMessages());
    }

    /**
     * Test 2: Overlapping intraday booking blocks fixed screen availability
     */
    public function test_overlapping_active_booking_causes_ineligibility(): void
    {
        $today = Carbon::now()->addDays(10)->format('Y-m-d');
        $start = Carbon::parse($today . ' 10:00:00');
        $end = Carbon::parse($today . ' 14:00:00');

        // Create an existing confirmed intraday booking
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Intraday Confirmed Booking',
            'title' => 'Intraday Confirmed Booking',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => $start,
            'end_time' => $end,
            'daily_start_time' => '10:00',
            'daily_end_time' => '14:00',
            'subtotal' => 2000.00,
            'final_amount' => 2000.00,
            'price' => 2000.00,
            'status' => Booking::STATUS_APPROVED,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenIlluvision->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'line_subtotal' => 2000.00,
            'subtotal' => 2000.00,
            'status' => 'confirmed',
        ]);

        $items = [
            ['screen_id' => $this->fixedScreenIlluvision->id, 'quantity' => 1],
        ];

        // Attempting to book an overlapping time window (12:00 - 16:00) on the same date must fail
        $overlapStart = $today . ' 12:00:00';
        $overlapEnd = $today . ' 16:00:00';

        $result = $this->capacityService->checkEligibility(
            $items,
            $overlapStart,
            $overlapEnd
        );

        $this->assertFalse($result->isEligible());
        $this->assertNotEmpty($result->getConflictMessages());
    }

    /**
     * Test 3: Cancelled and expired bookings release capacity
     */
    public function test_cancelled_and_expired_bookings_do_not_block_availability(): void
    {
        $start = Carbon::now()->addDays(15)->startOfDay();
        $end = Carbon::now()->addDays(17)->endOfDay();

        // Create a CANCELLED booking
        $cancelledBooking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Cancelled Booking',
            'title' => 'Cancelled Booking',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => $start,
            'end_time' => $end,
            'subtotal' => 2000.00,
            'final_amount' => 2000.00,
            'price' => 2000.00,
            'status' => Booking::STATUS_CANCELLED,
        ]);

        BookingScreen::create([
            'booking_id' => $cancelledBooking->id,
            'screen_id' => $this->fixedScreenIlluvision->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'unit_price' => 1000.00,
            'line_subtotal' => 2000.00,
            'subtotal' => 2000.00,
            'status' => 'cancelled',
        ]);

        $items = [
            ['screen_id' => $this->fixedScreenIlluvision->id, 'quantity' => 1],
        ];

        $result = $this->capacityService->checkEligibility(
            $items,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s')
        );

        $this->assertTrue($result->isEligible(), 'Cancelled booking must not consume capacity');
    }

    /**
     * Test 4: Mobile Fleet capacity limits and line sweep
     */
    public function test_mobile_fleet_capacity_limit_enforcement(): void
    {
        $start = Carbon::now()->addDays(20)->format('Y-m-d');
        $end = Carbon::now()->addDays(21)->format('Y-m-d');

        // Mobile fleet has fleet_size = 10
        // Requesting 10 units -> eligible
        $validItems = [
            ['screen_id' => $this->mobileFleetScreen->id, 'quantity' => 10],
        ];
        $validResult = $this->capacityService->checkEligibility($validItems, $start, $end);
        $this->assertTrue($validResult->isEligible(), 'Requesting exact fleet capacity must succeed');

        // Requesting 11 units -> exceeds fleet_size 10 -> ineligibility
        $overLimitItems = [
            ['screen_id' => $this->mobileFleetScreen->id, 'quantity' => 11],
        ];
        $overLimitResult = $this->capacityService->checkEligibility($overLimitItems, $start, $end);
        $this->assertFalse($overLimitResult->isEligible(), 'Requesting over fleet capacity must be rejected');
        $this->assertStringContainsString('units available', $overLimitResult->getFirstErrorMessage());
    }

    /**
     * Test 5: Endpoint POST /api/v2/availability/check preserves response shape
     */
    public function test_v2_availability_check_api_endpoint_flow(): void
    {
        $payload = [
            'start_date' => Carbon::now()->addDays(25)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(26)->format('Y-m-d'),
            'items' => [
                [
                    'asset_id' => $this->fixedScreenIlluvision->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/v2/availability/check', $payload);

        $response->assertStatus(200);
        $this->assertTrue($response->json('available'));
        $this->assertTrue($response->json('eligible'));
        $this->assertEmpty($response->json('conflicts'));
    }
}
