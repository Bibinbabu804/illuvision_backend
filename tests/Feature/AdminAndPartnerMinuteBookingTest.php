<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAndPartnerMinuteBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $partnerUser;
    protected Partner $partner;
    protected User $client;
    protected AdvertisementCategory $category;
    protected PricingPlan $minutePlan;
    protected PricingPlan $hourlyPlan;
    protected Screen $fixedScreen;
    protected Screen $mobileScreen;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Admin setup
        $this->admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@illuvision.net',
            'role' => 'super-admin',
            'status' => 'active',
        ]);

        // 2. Partner setup
        $this->partnerUser = User::factory()->create([
            'name' => 'Ad Partner User',
            'email' => 'partner@agency.com',
            'role' => 'partner',
            'status' => 'active',
        ]);

        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Premier Media Partner',
            'contact_person' => 'Partner Director',
            'email' => 'partner@agency.com',
            'phone' => '+919876543210',
            'status' => Partner::STATUS_APPROVED,
        ]);

        // 3. Client setup
        $this->client = User::factory()->create([
            'name' => 'Target Advertiser',
            'email' => 'advertiser@client.com',
            'phone' => '+919988776655',
            'role' => 'client',
            'status' => 'active',
        ]);

        // 4. Geo setup
        $country = Country::create(['name' => 'India', 'iso_code' => 'IN', 'currency' => 'INR']);
        $state = State::create(['name' => 'Maharashtra', 'country_id' => $country->id]);
        $city = City::create(['name' => 'Mumbai', 'state_id' => $state->id, 'country_id' => $country->id]);
        $categoryModel = ScreenCategory::create(['name' => 'Digital Billboard']);

        $this->category = AdvertisementCategory::create([
            'name' => 'Automotive',
            'slug' => 'automotive',
            'status' => 'active',
        ]);

        // 5. Minute Pricing Plan (30 minutes minimum @ ₹10 per minute)
        $this->minutePlan = PricingPlan::create([
            'name' => 'Flash 30-Min Promo Plan',
            'base_price' => 10.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
        ]);

        // 6. Hourly Pricing Plan (1 Hour minimum @ ₹1,000 base)
        $this->hourlyPlan = PricingPlan::create([
            'name' => 'Standard Hourly Plan',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);

        // 7. Provider and Screens setup
        $provider = ScreenProvider::create([
            'provider_name' => 'Illuvision Media Network',
            'contact_person' => 'Operations Head',
            'email' => 'ops@illuvision.net',
            'phone' => '+919876543200',
            'status' => 'active',
        ]);

        // Fixed screen with 30-minute plan
        $this->fixedScreen = Screen::create([
            'name' => 'Marine Drive Digital Billboard',
            'screen_code' => 'MUM-MD-001',
            'asset_type' => 'fixed',
            'screen_type' => 'fixed',
            'category_id' => $categoryModel->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'screen_provider_id' => $provider->id,
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
            'location_name' => 'Mumbai Marine Drive',
            'status' => 'active',
            'booking_status' => 'available',
            'slots_per_loop' => 10,
            'max_ads_per_day' => 100,
        ]);

        // Mobile fleet screen (100 units)
        $this->mobileScreen = Screen::create([
            'name' => 'Mumbai Transit Fleet',
            'screen_code' => 'MUM-FLEET-001',
            'asset_type' => 'mobile',
            'screen_type' => 'mobile',
            'category_id' => $categoryModel->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'screen_provider_id' => $provider->id,
            'country_id' => $country->id,
            'state_id' => $state->id,
            'city_id' => $city->id,
            'location_name' => 'Mumbai Metropolitan Area',
            'status' => 'active',
            'booking_status' => 'available',
            'fleet_size' => 100,
            'slots_per_loop' => 10,
            'max_ads_per_day' => 100,
        ]);
    }

    /**
     * Test Admin creates a 30-minute manual campaign booking.
     */
    public function test_admin_can_create_30_minute_manual_campaign()
    {
        $payload = [
            'client' => [
                'name' => 'Acme Corporation',
                'email' => 'acme@corp.com',
                'phone' => '+919876500000',
            ],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Flash 30-Min Sale Announcement',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'discount_amount' => 0.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
            'payment_mode' => 'bank_transfer',
            'offline_payment_reference' => 'TXN-BANK-12345',
            'bypass_approval' => true,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', $payload);

        $response->assertStatus(201);
        $bookingData = $response->json('data');

        $this->assertEquals('Flash 30-Min Sale Announcement', $bookingData['title']);
        // Authoritative pricing automatically applies First Booking Discount (15% on new user) -> ₹255.00
        $this->assertEquals(255.00, $bookingData['final_amount']);

        // Verify exact timestamp persistence in database
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingData['id'],
            'title' => 'Flash 30-Min Sale Announcement',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'final_amount' => 255.00,
        ]);
    }

    /**
     * Test Ad Partner calculates and books a 30-minute campaign.
     */
    public function test_partner_can_calculate_and_book_30_minute_campaign()
    {
        // 1. Partner preview calculation
        $calcRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings/calculate', [
            'screen_ids' => [$this->fixedScreen->id],
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ]);

        $calcRes->assertStatus(200);
        $calcData = $calcRes->json('data');
        $this->assertEquals(300.00, $calcData['aggregated']['final_amount']);

        // 2. Partner booking creation
        $bookRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', [
            'client' => [
                'name' => 'Partner Direct Client',
                'email' => 'direct@partnerclient.com',
                'phone' => '+919988112233',
            ],
            'campaign_title' => 'Partner Flash Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
            'payment_mode' => 'direct_bank',
        ]);

        $bookRes->assertStatus(201);
        $booking = $bookRes->json('data.booking');

        $this->assertEquals('Partner Flash Campaign', $booking['title']);
        $this->assertEquals($this->partner->id, $booking['created_by_partner_id']);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking['id'],
            'created_by_partner_id' => $this->partner->id,
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
        ]);
    }

    /**
     * Test Minimum Duration validation rejects sub-minimum requests.
     */
    public function test_minimum_duration_rejection_for_both_channels()
    {
        // Screen attached to 1-Hour minimum plan
        $this->fixedScreen->update(['pricing_plan_id' => $this->hourlyPlan->id]);

        // 1. Admin attempt for 30 minutes on 1-hour minimum plan
        $adminRes = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', [
            'client' => ['name' => 'Test Client'],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Sub-minimum campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
        ]);

        $adminRes->assertStatus(422);

        // 2. Partner attempt for 30 minutes on 1-hour minimum plan
        $partnerRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', [
            'client' => ['name' => 'Test Client'],
            'campaign_title' => 'Sub-minimum partner campaign',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'advertisement_category_id' => $this->category->id,
        ]);

        $partnerRes->assertStatus(422);
    }

    /**
     * Test Fixed Screen overlapping interval conflict between Admin and Partner.
     */
    public function test_fixed_screen_overlapping_interval_conflict()
    {
        // 1. Admin books 10:00 to 10:30
        $adminRes = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', [
            'client' => ['name' => 'Admin First Booking'],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Admin Slot 10:00-10:30',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
            'bypass_approval' => true,
        ]);
        $adminRes->assertStatus(201);

        // 2. Conflict check verifies interval conflict
        $checkRes = $this->postJson('/api/bookings/check-conflicts', [
            'screen_id' => $this->fixedScreen->id,
            'start_time' => '2026-08-28 10:15:00',
            'end_time' => '2026-08-28 10:45:00',
        ]);
        $checkRes->assertStatus(200);
        $this->assertFalse($checkRes->json('data.has_available_capacity'));
    }

    /**
     * Test Adjacent non-overlapping minute bookings both succeed.
     */
    public function test_adjacent_minute_bookings_both_succeed()
    {
        // 1. Admin books 10:00 to 10:30
        $adminRes = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', [
            'client' => ['name' => 'Slot A Client'],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Morning Slot A',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
            'bypass_approval' => true,
        ]);
        $adminRes->assertStatus(201);

        // 2. Partner books adjacent 10:30 to 11:00
        $partnerRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', [
            'client' => ['name' => 'Slot B Client'],
            'campaign_title' => 'Morning Slot B',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:30:00',
            'end_time' => '2026-08-28 11:00:00',
            'daily_start_time' => '10:30',
            'daily_end_time' => '11:00',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300.00,
            'final_amount' => 300.00,
            'advertisement_category_id' => $this->category->id,
            'payment_mode' => 'direct_bank',
        ]);
        $partnerRes->assertStatus(201);
    }

    /**
     * Test Mobile fleet concurrent capacity enforcement between Admin and Partner.
     */
    public function test_mobile_fleet_concurrent_capacity_enforcement()
    {
        // 1. Admin books 60 units of 100 fleet from 10:00 to 11:00 (60 min @ ₹10/min * 60 units = ₹36,000)
        $adminRes = $this->actingAs($this->admin)->postJson('/api/admin/manual-bookings', [
            'client' => ['name' => 'Fleet Client 1'],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'Mobile Fleet Batch 1',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 11:00:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '11:00',
            'screen_ids' => [$this->mobileScreen->id],
            'quantities' => [$this->mobileScreen->id => 60],
            'total_screens' => 1,
            'subtotal' => 36000.00,
            'final_amount' => 31000.00,
            'advertisement_category_id' => $this->category->id,
            'bypass_approval' => true,
        ]);
        $adminRes->assertStatus(201);

        // 2. Partner attempts to book 50 units during overlapping interval 10:30 to 11:30 (60 + 50 = 110 > 100) -> rejected
        $overRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', [
            'client' => ['name' => 'Fleet Client 2'],
            'campaign_title' => 'Mobile Fleet Batch 2 Over Capacity',
            'start_time' => '2026-08-28 10:30:00',
            'end_time' => '2026-08-28 11:30:00',
            'daily_start_time' => '10:30',
            'daily_end_time' => '11:30',
            'screen_ids' => [$this->mobileScreen->id],
            'quantities' => [$this->mobileScreen->id => 50],
            'advertisement_category_id' => $this->category->id,
        ]);
        $overRes->assertStatus(422);

        // 3. Partner attempts to book 40 units during overlapping interval 10:30 to 11:30 (60 + 40 = 100 <= 100) -> allowed! (60 min @ ₹10/min * 40 units = ₹24,000)
        $validRes = $this->actingAs($this->partnerUser)->postJson('/api/partner/bookings', [
            'client' => ['name' => 'Fleet Client 3'],
            'campaign_title' => 'Mobile Fleet Batch 3 Valid Capacity',
            'start_time' => '2026-08-28 10:30:00',
            'end_time' => '2026-08-28 11:30:00',
            'daily_start_time' => '10:30',
            'daily_end_time' => '11:30',
            'screen_ids' => [$this->mobileScreen->id],
            'quantities' => [$this->mobileScreen->id => 40],
            'advertisement_category_id' => $this->category->id,
            'payment_mode' => 'direct_bank',
        ]);
        $validRes->assertStatus(201);
    }
}
