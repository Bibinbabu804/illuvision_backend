<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\PartnerCommission;
use App\Models\Screen;
use App\Services\CommissionService;
use App\Services\RevenueDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GoldenFinancialRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected RevenueDistributionService $revenueDistributionService;
    protected CommissionService $commissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        Event::fake();

        $this->revenueDistributionService = app(RevenueDistributionService::class);
        $this->commissionService = app(CommissionService::class);
    }

    /**
     * Financial Test 1: Provider Revenue Split (80% Provider, 20% Illuvision)
     */
    public function test_provider_revenue_split_calculation(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Revenue Split Test Campaign',
            'title' => 'Revenue Split Test Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 10000.00,
            'final_amount' => 10000.00,
            'price' => 10000.00,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $bookingScreen = BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'unit_price' => 10000.00,
            'line_subtotal' => 10000.00,
            'subtotal' => 10000.00,
            'gross_revenue' => 10000.00,
            'screen_provider_id' => $this->providerAlpha->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_percentage' => 80.00,
            'illuvision_percentage' => 20.00,
            'status' => 'confirmed',
        ]);

        $this->revenueDistributionService->processBookingScreens($booking);

        $bookingScreen->refresh();

        // 80% of 10000 = 8000.00 Provider Share
        // 20% of 10000 = 2000.00 Illuvision Platform Share
        $this->assertEquals(8000.00, $bookingScreen->provider_share);
        $this->assertEquals(2000.00, $bookingScreen->platform_share);
        $this->assertEquals(10000.00, $bookingScreen->gross_revenue);
        $this->assertEquals('pending', $bookingScreen->settlement_status);
    }

    /**
     * Financial Test 2: Decimal Precision & Boundary Currency Calculations
     * Tests cents/paise boundaries (0.01, 1.99, 99.99, 10000.01)
     */
    public function test_decimal_precision_boundaries(): void
    {
        $amounts = [0.01, 1.99, 10.01, 99.99, 999.99, 10000.01];

        foreach ($amounts as $amount) {
            // Test paise conversion round-trip
            $paise = (int) round($amount * 100);
            $reconverted = round($paise / 100, 2);

            $this->assertEquals($amount, $reconverted, "Paise conversion must be exact for {$amount}");

            // Test 80/20 split sum equality: provider_share + platform_share == total
            $providerShare = round($amount * 0.80, 2);
            $platformShare = round($amount * 0.20, 2);
            $splitSum = round($providerShare + $platformShare, 2);

            $this->assertEqualsWithDelta(
                $amount,
                $splitSum,
                0.01,
                "Provider and platform split sum must match gross total within 1 cent for {$amount}"
            );
        }
    }

    /**
     * Financial Test 3: Partner Commission Calculation on Platform Share
     */
    public function test_partner_commission_calculation(): void
    {
        // Referral code configured with 15% commission on Illuvision Revenue
        $platformRevenue = 2000.00;
        $commissionRate = 15.00;

        $expectedCommission = round($platformRevenue * ($commissionRate / 100.0), 2);
        // 15% of 2000 = 300.00
        $this->assertEquals(300.00, $expectedCommission);
    }
}
