<?php

namespace Tests\Feature;
use App\Support\Events\Notifications\CommissionGenerated;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\Payment;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\Campaign\ReferralTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdPartnerCommissionSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Partner $partner;
    protected User $partnerUser;
    protected ReferralCode $referralCode;
    protected Country $country;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::create([
            'name' => 'India',
            'code' => 'IN',
            'phone_code' => '+91',
            'currency_code' => 'INR',
            'currency_symbol' => '₹',
            'status' => 'active',
        ]);

        $this->city = City::create([
            'name' => 'Mumbai',
            'country_id' => $this->country->id,
            'status' => 'active',
        ]);

        $this->partnerUser = User::factory()->create([
            'role' => 'partner',
            'status' => 'active',
        ]);

        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Apex Media Partner',
            'contact_person' => 'Rajesh Sharma',
            'email' => 'partner@apexmedia.com',
            'phone' => '9876543210',
            'city' => 'Mumbai',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $this->referralCode = ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'APEX20',
            'status' => 'active',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'commission_type' => 'percentage',
            'commission_value' => 20, // 20% on Illuvision Revenue
            'usage_limit' => 0,
            'used_count' => 0,
            'start_date' => now()->subDay(),
            'expiry_date' => now()->addYear(),
        ]);
    }

    public function test_profit_based_commission_formula_and_snapshots(): void
    {
        Event::fake([CommissionGenerated::class]);

        $provider = ScreenProvider::create([
            'provider_name' => 'Metro Screens',
            'business_name' => 'Metro Screens Pvt Ltd',
            'contact_person' => 'Metro Admin',
            'email' => 'provider@metro.com',
            'phone' => '9998887776',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'status' => 'approved',
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);

        $providerScreen = Screen::create([
            'name' => 'Highway Billboard A',
            'screen_code' => 'SCR-HW-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Western Express Highway, Andheri',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'base_price_per_day' => 1000,
            'status' => 'active',
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);

        $illuvisionScreen = Screen::create([
            'name' => 'Mall Display Prime',
            'screen_code' => 'SCR-ML-02',
            'screen_type' => 'indoor_mall',
            'location_name' => 'Phoenix Mall, Lower Parel',
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'base_price_per_day' => 1000,
            'status' => 'active',
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
        ]);

        $clientUser = User::factory()->create(['role' => 'user']);

        $booking = Booking::create([
            'user_id' => $clientUser->id,
            'referral_code_id' => $this->referralCode->id,
            'title' => 'Festival Campaign',
            'campaign_title' => 'Festival Campaign',
            'advertiser_name' => $clientUser->name,
            'advertiser_email' => $clientUser->email,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDays(5),
            'price' => 100000.00,
            'subtotal' => 100000.00,
            'discount_amount' => 10000.00,
            'referral_discount' => 10000.00,
            'final_amount' => 90000.00, // Total paid = ₹90,000
            'total_screens' => 2,
            'status' => Booking::STATUS_PENDING_APPROVAL,
            'source' => 'website',
        ]);

        // Each screen gets half gross revenue: ₹45,000
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $providerScreen->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'gross_revenue' => 45000.00,
            'platform_share' => 6750.00, // 15% of 45,000
            'provider_share' => 38250.00, // 85% of 45,000
            'subtotal' => 45000.00,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $illuvisionScreen->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'gross_revenue' => 45000.00,
            'platform_share' => 45000.00, // 100% of 45,000
            'provider_share' => 0.00,
            'subtotal' => 45000.00,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 90000.00,
            'currency' => 'INR',
            'payment_status' => Payment::STATUS_PAID,
            'payment_method' => 'razorpay',
            'paid_at' => now(),
        ]);

        // Trigger referral tracking
        $trackingService = app(ReferralTrackingService::class);
        $result = $trackingService->createReferralTracking($booking, $payment->id, $this->referralCode);

        $usage = $result['usage'];
        $commission = $result['commission'];

        $this->assertNotNull($usage);
        $this->assertNotNull($commission);

        // Total Illuvision Revenue = 6,750 + 45,000 = 51,750.00
        // Partner Commission = 20% of 51,750.00 = 10,350.00
        $this->assertEquals(90000.00, (float) $commission->campaign_total);
        $this->assertEquals(38250.00, (float) $commission->provider_revenue);
        $this->assertEquals(51750.00, (float) $commission->illuvision_revenue);
        $this->assertEquals(51750.00, (float) $commission->partner_commission_base);
        $this->assertEquals(20.00, (float) $commission->partner_commission_percentage);
        $this->assertEquals(10350.00, (float) $commission->amount);
        $this->assertEquals(PartnerCommission::STATUS_PENDING, $commission->status);

        Event::assertDispatched(CommissionGenerated::class, function ($event) use ($commission) {
            return $event->commission->id === $commission->id;
        });
    }

    public function test_duplicate_referral_tracking_is_idempotent(): void
    {
        $clientUser = User::factory()->create(['role' => 'user']);
        $booking = Booking::create([
            'user_id' => $clientUser->id,
            'referral_code_id' => $this->referralCode->id,
            'title' => 'Single Screen Campaign',
            'campaign_title' => 'Single Screen Campaign',
            'advertiser_name' => $clientUser->name,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDays(2),
            'price' => 20000.00,
            'final_amount' => 20000.00,
            'total_screens' => 1,
            'status' => Booking::STATUS_PENDING_APPROVAL,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 20000.00,
            'currency' => 'INR',
            'payment_status' => Payment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $trackingService = app(ReferralTrackingService::class);

        $firstResult = $trackingService->createReferralTracking($booking, $payment->id, $this->referralCode);
        $secondResult = $trackingService->createReferralTracking($booking, $payment->id, $this->referralCode);

        $this->assertEquals($firstResult['usage']->id, $secondResult['usage']->id);
        $this->assertEquals($firstResult['commission']->id, $secondResult['commission']->id);

        $this->assertEquals(1, ReferralUsage::where('booking_id', $booking->id)->count());
        $this->assertEquals(1, PartnerCommission::where('booking_id', $booking->id)->count());
    }

    public function test_commission_service_lifecycle_and_transition_guards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientUser = User::factory()->create(['role' => 'user']);

        $booking = Booking::create([
            'user_id' => $clientUser->id,
            'title' => 'Test Booking',
            'campaign_title' => 'Test Booking',
            'advertiser_name' => $clientUser->name,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDays(2),
            'price' => 10000.00,
            'final_amount' => 10000.00,
            'total_screens' => 1,
            'status' => Booking::STATUS_PENDING_APPROVAL,
        ]);

        $usage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'booking_id' => $booking->id,
            'user_id' => $clientUser->id,
            'partner_id' => $this->partner->id,
            'status' => ReferralUsage::STATUS_CONFIRMED,
        ]);

        $commission = PartnerCommission::create([
            'partner_id' => $this->partner->id,
            'referral_usage_id' => $usage->id,
            'booking_id' => $booking->id,
            'commission_type' => 'percentage',
            'commission_value' => 20,
            'amount' => 2000.00,
            'status' => PartnerCommission::STATUS_PENDING,
            'generated_at' => now(),
        ]);

        $service = app(CommissionService::class);

        // 1. Approve
        $approved = $service->approve($commission->id, $admin->id, 'Verified and approved');
        $this->assertEquals(PartnerCommission::STATUS_APPROVED, $approved->status);
        $this->assertNotNull($approved->approved_at);

        // 2. Mark Paid
        $paid = $service->markAsPaid($approved->id, 'Bank Transfer Completed', [
            'payout_reference' => 'TXN-987654321',
            'payout_method' => 'bank_transfer',
            'payout_date' => now()->toDateString(),
        ]);
        $this->assertEquals(PartnerCommission::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
        $this->assertEquals('TXN-987654321', $paid->payout_reference);

        // 3. Guard: Cannot approve already paid commission
        $this->expectException(\InvalidArgumentException::class);
        $service->approve($paid->id, $admin->id);
    }

    public function test_booking_cancellation_automatically_cancels_pending_commission(): void
    {
        $clientUser = User::factory()->create(['role' => 'user']);
        $booking = Booking::create([
            'user_id' => $clientUser->id,
            'referral_code_id' => $this->referralCode->id,
            'title' => 'Cancelled Campaign',
            'campaign_title' => 'Cancelled Campaign',
            'advertiser_name' => $clientUser->name,
            'start_time' => now()->addDay(),
            'end_time' => now()->addDays(2),
            'price' => 15000.00,
            'final_amount' => 15000.00,
            'total_screens' => 1,
            'status' => Booking::STATUS_PENDING_APPROVAL,
        ]);

        $usage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'booking_id' => $booking->id,
            'user_id' => $clientUser->id,
            'partner_id' => $this->partner->id,
            'status' => ReferralUsage::STATUS_CONFIRMED,
        ]);

        $commission = PartnerCommission::create([
            'partner_id' => $this->partner->id,
            'referral_usage_id' => $usage->id,
            'booking_id' => $booking->id,
            'commission_type' => 'percentage',
            'commission_value' => 20,
            'amount' => 3000.00,
            'status' => PartnerCommission::STATUS_PENDING,
        ]);

        // Cancel the booking
        $booking->update(['status' => Booking::STATUS_CANCELLED]);

        $commission->refresh();
        $usage->refresh();

        $this->assertEquals(PartnerCommission::STATUS_CANCELLED, $commission->status);
        $this->assertEquals(ReferralUsage::STATUS_CANCELLED, $usage->status);
    }

    public function test_partner_portal_tenant_isolation(): void
    {
        $otherPartnerUser = User::factory()->create(['role' => 'partner']);
        $otherPartner = Partner::create([
            'user_id' => $otherPartnerUser->id,
            'name' => 'Rival Media',
            'contact_person' => 'Rival Admin',
            'email' => 'other@rival.com',
            'phone' => '9991112233',
            'city' => 'Delhi',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $otherUsage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'status' => ReferralUsage::STATUS_CONFIRMED,
        ]);

        PartnerCommission::create([
            'partner_id' => $otherPartner->id,
            'referral_usage_id' => $otherUsage->id,
            'commission_type' => 'percentage',
            'amount' => 8888.00,
            'status' => PartnerCommission::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->partnerUser)
            ->getJson('/api/partner/commissions');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        foreach ($data as $item) {
            $this->assertNotEquals(8888.00, $item['amount']);
        }
    }
}
