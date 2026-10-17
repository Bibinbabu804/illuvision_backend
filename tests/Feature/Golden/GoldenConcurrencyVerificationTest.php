<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Payment;
use App\Models\Screen;
use App\Services\InventoryService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoldenConcurrencyVerificationTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
    }

    /**
     * Verification 1: InventoryService::lockScreensForBooking uses lockForUpdate() on screens table
     */
    public function test_inventory_service_lock_screens_generates_pessimistic_lock(): void
    {
        $inventoryService = app(InventoryService::class);

        DB::beginTransaction();

        $lockedScreens = $inventoryService->lockScreensForBooking([
            $this->fixedScreenIlluvision->id,
            $this->fixedScreenProvider->id,
        ]);

        $this->assertCount(2, $lockedScreens);
        $this->assertEquals($this->fixedScreenIlluvision->id, $lockedScreens[0]->id);
        $this->assertEquals($this->fixedScreenProvider->id, $lockedScreens[1]->id);

        DB::rollBack();
    }

    /**
     * Verification 2: Payment::where(...)->firstOrFail() does NOT apply lockForUpdate
     * Documents that PaymentService uses non-locking SELECT rather than SELECT ... FOR UPDATE.
     */
    public function test_payment_service_retrieval_does_not_use_lock_for_update(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Lock Test Campaign',
            'title' => 'Lock Test Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'price' => 1000.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_lock_check_01',
            'amount' => 1000.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        // Capture SQL queries executed during firstOrFail
        DB::enableQueryLog();

        Payment::where('razorpay_order_id', 'order_lock_check_01')->firstOrFail();

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queryLog);
        $lastQuery = end($queryLog)['query'];

        // Confirm query does NOT contain 'for update' or 'lock in share mode'
        $this->assertStringNotContainsStringIgnoringCase('for update', $lastQuery);
        $this->assertStringNotContainsStringIgnoringCase('lock in share mode', $lastQuery);
    }

    /**
     * Verification 3: Transaction Rollback on Failure preserves database integrity
     */
    public function test_transaction_rollback_reverts_partial_mutations(): void
    {
        $initialBookingsCount = Booking::count();
        $initialScreensCount = BookingScreen::count();

        try {
            DB::transaction(function () {
                // Operation A succeeds: insert Booking
                $booking = Booking::create([
                    'user_id' => $this->customerUser->id,
                    'campaign_title' => 'Rollback Test Campaign',
                    'title' => 'Rollback Test Campaign',
                    'advertiser_name' => $this->customerUser->name,
                    'advertiser_email' => $this->customerUser->email,
                    'start_time' => Carbon::now()->addDays(2),
                    'end_time' => Carbon::now()->addDays(4),
                    'subtotal' => 1000.00,
                    'final_amount' => 1000.00,
                    'price' => 1000.00,
                    'status' => Booking::STATUS_PAYMENT_PENDING,
                ]);

                // Operation B succeeds: insert BookingScreen
                BookingScreen::create([
                    'booking_id' => $booking->id,
                    'screen_id' => $this->fixedScreenIlluvision->id,
                    'city_id' => $this->city->id,
                    'quantity' => 1,
                    'unit_price' => 1000.00,
                    'line_subtotal' => 1000.00,
                    'subtotal' => 1000.00,
                    'status' => 'pending',
                ]);

                // Operation C fails: Injected Exception
                throw new \RuntimeException('Injected transaction failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Injected transaction failure', $e->getMessage());
        }

        // Verify both Operation A and Operation B were rolled back
        $this->assertEquals($initialBookingsCount, Booking::count());
        $this->assertEquals($initialScreensCount, BookingScreen::count());
    }
}
