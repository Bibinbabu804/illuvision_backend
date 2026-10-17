<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\ReferralCode;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use App\Services\ManualBookingService;
use App\Services\Campaign\ReferralTrackingService;
use App\Services\RevenueDistributionService;
use App\Services\RevenueSplitResolverService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingChannelDurationSplitIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $partnerUser;
    protected User $customerUser;
    protected Partner $partner;
    protected ReferralCode $referralCode;
    protected ScreenProvider $providerAlpha;
    protected PricingPlan $minutePlan;
    protected PricingDurationRule $rule30m;
    protected PricingDurationRule $rule60m;
    protected PricingDurationRule $rule90m;
    protected PricingDurationRule $rule120m;
    protected PricingDurationRule $rule60mInactive;
    protected ScreenCategory $billboardCategory;
    protected AdvertisementCategory $adCategory;
    protected Country $country;
    protected State $state;
    protected City $city;

    protected Screen $screenProviderFallback;
    protected Screen $screenStaticOverride;
    protected Screen $screenDurationOverride;
    protected Screen $screenDirectIlluvision;
    protected Screen $screenMobileFleet;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Geography & Categories
        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->state = State::firstOrCreate(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::firstOrCreate(['name' => 'Kochi', 'state_id' => $this->state->id], ['country_id' => $this->country->id]);
        $this->billboardCategory = ScreenCategory::firstOrCreate(['slug' => 'digital-billboard'], ['name' => 'Digital Billboard']);
        $this->adCategory = AdvertisementCategory::firstOrCreate(['slug' => 'retail'], ['name' => 'Retail & E-commerce', 'status' => 'active']);

        // 2. Users
        $this->adminUser = User::factory()->create(['role' => 'superadmin', 'status' => 'active']);
        $this->customerUser = User::factory()->create(['role' => 'user', 'name' => 'Alice Customer', 'email' => 'alice@customer.com', 'status' => 'active']);
        $this->partnerUser = User::factory()->create(['role' => 'partner', 'name' => 'Bob Partner', 'email' => 'bob@partner.com', 'status' => 'active']);

        // 3. Partner & Referral Code
        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Bob Partner Agency',
            'contact_person' => 'Bob Partner',
            'email' => 'bob@partner.com',
            'phone' => '+919876543210',
            'city' => 'Kochi',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $this->referralCode = ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'BOB10',
            'discount_type' => 'percentage',
            'discount_value' => 5.00,
            'commission_type' => 'percentage',
            'commission_value' => 10.00,
            'start_date' => now()->subDay(),
            'expiry_date' => now()->addYear(),
            'status' => 'active',
        ]);

        // 4. Screen Provider
        $this->providerAlpha = ScreenProvider::create([
            'provider_name' => 'Alpha Media Group',
            'business_name' => 'Alpha Media Ltd',
            'contact_person' => 'John Doe',
            'phone' => '+919999999999',
            'email' => 'alpha@medialtd.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00, // Provider default: 60% Provider / 40% Illuvision
        ]);

        // 5. Minute Pricing Plan & Rules
        $this->minutePlan = PricingPlan::create([
            'name' => 'Dynamic Minute Pricing Plan',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->rule30m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 30.00,
            'maximum_duration' => 59.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 0.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->rule60m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 60.00,
            'maximum_duration' => 89.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 5.00,
            'sort_order' => 2,
            'status' => 'active',
        ]);

        $this->rule90m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 90.00,
            'maximum_duration' => 119.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 3,
            'status' => 'active',
        ]);

        $this->rule120m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 120.00,
            'maximum_duration' => 240.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 15.00,
            'sort_order' => 4,
            'status' => 'active',
        ]);

        $this->rule60mInactive = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 600.00,
            'maximum_duration' => 700.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 20.00,
            'sort_order' => 5,
            'status' => 'inactive',
        ]);

        // 6. Screens
        // Screen A: Provider fallback (No duration split) -> inherits 40/60
        $this->screenProviderFallback = Screen::create([
            'name' => 'Screen Alpha Fallback',
            'screen_code' => 'SCR-ALPHA-01',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Alpha Tower',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Screen B: Screen static override (70/30) without duration split
        $this->screenStaticOverride = Screen::create([
            'name' => 'Screen Beta Static Override',
            'screen_code' => 'SCR-BETA-02',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Beta Plaza',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Screen C: Screen with Static Split (70/30) AND Duration Overrides:
        // 30m -> 60/40, 60m -> 65/35, 90m -> 70/30, 120m -> 75/25
        $this->screenDurationOverride = Screen::create([
            'name' => 'Screen Gamma Duration Overrides',
            'screen_code' => 'SCR-GAMMA-03',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Gamma Center',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule30m->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule60m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule90m->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'is_active' => true,
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule120m->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
            'is_active' => true,
        ]);

        // Screen D: Direct Illuvision screen -> 100/0 absolute precedence
        $this->screenDirectIlluvision = Screen::create([
            'name' => 'Screen Direct Illuvision',
            'screen_code' => 'SCR-ILLU-04',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Illuvision HQ',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Screen E: Mobile fleet asset with 60m override (65/35)
        $this->screenMobileFleet = Screen::create([
            'name' => 'Mobile Fleet Screen #1',
            'screen_code' => 'SCR-MOB-05',
            'screen_type' => 'Mobile LED Van',
            'asset_type' => 'mobile',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Mobile Fleet Depot',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'fleet_size' => 5,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenMobileFleet->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule60m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);
    }

    /**
     * Helper to build booking request payload.
     */
    protected function makeBookingPayload(array $screenIds, string $startTime, string $endTime, array $overrides = []): array
    {
        return array_merge([
            'campaign_title' => 'Test Campaign Integration',
            'ad_frequency' => 'every_5_min',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'daily_start_time' => date('H:i', strtotime($startTime)),
            'daily_end_time' => date('H:i', strtotime($endTime)),
            'screen_ids' => $screenIds,
            'total_screens' => count($screenIds),
            'quantities' => array_fill_keys($screenIds, 1),
            'subtotal' => 1000.00,
            'discount_amount' => 0.00,
            'final_amount' => 1000.00,
            'pricing_plan_id' => $this->minutePlan->id,
            'advertisement_category_id' => $this->adCategory->id,
            'booking_source' => 'admin_manual',
            'client' => [
                'name' => 'Client Integration',
                'email' => 'client.integration@test.com',
                'phone' => '+919876500000',
            ],
        ], $overrides);
    }

    /** @test */
    public function admin_manual_booking_resolves_all_duration_splits_and_persists_financial_snapshots()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. 30 Minutes -> Resolves 30m override (40% Illuvision / 60% Provider)
        $payload30 = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-01 10:00:00',
            '2026-09-01 10:30:00'
        );
        $res30 = $this->postJson('/api/admin/manual-bookings', $payload30);
        $res30->assertStatus(201);
        $b30Id = $res30->json('data.id');
        $bs30 = BookingScreen::where('booking_id', $b30Id)->first();
        $this->assertEquals(40.00, (float) $bs30->illuvision_percentage);
        $this->assertEquals(60.00, (float) $bs30->screen_provider_percentage);
        $this->assertEquals(round($bs30->gross_revenue * 0.40, 2), (float) $bs30->platform_share);
        $this->assertEquals(round($bs30->gross_revenue * 0.60, 2), (float) $bs30->provider_share);

        // 2. 60 Minutes -> Resolves 60m override (35% Illuvision / 65% Provider)
        $payload60 = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-01 11:00:00',
            '2026-09-01 12:00:00'
        );
        $res60 = $this->postJson('/api/admin/manual-bookings', $payload60);
        $res60->assertStatus(201);
        $b60Id = $res60->json('data.id');
        $bs60 = BookingScreen::where('booking_id', $b60Id)->first();
        $this->assertEquals(35.00, (float) $bs60->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bs60->screen_provider_percentage);
        $this->assertEquals(round($bs60->gross_revenue * 0.35, 2), (float) $bs60->platform_share);
        $this->assertEquals(round($bs60->gross_revenue * 0.65, 2), (float) $bs60->provider_share);

        // 3. 90 Minutes -> Resolves 90m override (30% Illuvision / 70% Provider)
        $payload90 = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-01 13:00:00',
            '2026-09-01 14:30:00'
        );
        $res90 = $this->postJson('/api/admin/manual-bookings', $payload90);
        $res90->assertStatus(201);
        $b90Id = $res90->json('data.id');
        $bs90 = BookingScreen::where('booking_id', $b90Id)->first();
        $this->assertEquals(30.00, (float) $bs90->illuvision_percentage);
        $this->assertEquals(70.00, (float) $bs90->screen_provider_percentage);
        $this->assertEquals(round($bs90->gross_revenue * 0.30, 2), (float) $bs90->platform_share);
        $this->assertEquals(round($bs90->gross_revenue * 0.70, 2), (float) $bs90->provider_share);

        // 4. 120 Minutes -> Resolves 120m override (25% Illuvision / 75% Provider)
        $payload120 = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-01 15:00:00',
            '2026-09-01 17:00:00'
        );
        $res120 = $this->postJson('/api/admin/manual-bookings', $payload120);
        $res120->assertStatus(201);
        $b120Id = $res120->json('data.id');
        $bs120 = BookingScreen::where('booking_id', $b120Id)->first();
        $this->assertEquals(25.00, (float) $bs120->illuvision_percentage);
        $this->assertEquals(75.00, (float) $bs120->screen_provider_percentage);
        $this->assertEquals(round($bs120->gross_revenue * 0.25, 2), (float) $bs120->platform_share);
        $this->assertEquals(round($bs120->gross_revenue * 0.75, 2), (float) $bs120->provider_share);
    }

    /** @test */
    public function ad_partner_booking_resolves_identical_duration_splits()
    {
        Sanctum::actingAs($this->partnerUser);

        $payload = [
            'client' => [
                'name' => 'Partner Direct Client',
                'email' => 'partner.client@example.com',
                'phone' => '+919876543210',
            ],
            'campaign_title' => 'Partner 60m Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-02 10:00:00',
            'end_time' => '2026-09-02 11:00:00',
            'screen_ids' => [$this->screenDurationOverride->id],
            'quantities' => [$this->screenDurationOverride->id => 1],
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'advertisement_category_id' => $this->adCategory->id,
            // Injected malicious split attempting to override server authority
            'illuvision_percentage' => 10.00,
            'screen_provider_percentage' => 90.00,
        ];

        $response = $this->postJson('/api/partner/bookings', $payload);
        $response->assertStatus(201);

        $bookingId = $response->json('data.booking.id');
        $bookingScreen = BookingScreen::where('booking_id', $bookingId)->first();

        // Must ignore client-injected 10/90 and resolve authoritative 60m override (35% Illu / 65% Prov)
        $this->assertEquals(35.00, (float) $bookingScreen->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bookingScreen->screen_provider_percentage);
        $this->assertEquals(round($bookingScreen->gross_revenue * 0.35, 2), (float) $bookingScreen->platform_share);
        $this->assertEquals(round($bookingScreen->gross_revenue * 0.65, 2), (float) $bookingScreen->provider_share);
    }

    /** @test */
    public function customer_screen_booking_resolves_identical_duration_splits()
    {
        Sanctum::actingAs($this->customerUser);

        $payload = [
            'campaign_title' => 'Customer Direct 60m Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-03 14:00:00',
            'end_time' => '2026-09-03 15:00:00',
            'screen_ids' => [$this->screenDurationOverride->id],
            'quantities' => [$this->screenDurationOverride->id => 1],
            'total_screens' => 1,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'pricing_plan_id' => $this->minutePlan->id,
            'advertisement_category_id' => $this->adCategory->id,
        ];

        $response = $this->postJson('/api/bookings/campaign', $payload);
        $response->assertSuccessful();

        $bookingId = $response->json('data.id') ?? $response->json('data.booking.id');
        $bookingScreen = BookingScreen::where('booking_id', $bookingId)->first();

        // Resolves 60m override (35% Illu / 65% Prov)
        $this->assertEquals(35.00, (float) $bookingScreen->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bookingScreen->screen_provider_percentage);
        $this->assertEquals(round($bookingScreen->gross_revenue * 0.35, 2), (float) $bookingScreen->platform_share);
        $this->assertEquals(round($bookingScreen->gross_revenue * 0.65, 2), (float) $bookingScreen->provider_share);
    }

    /** @test */
    public function cross_channel_consistency_produces_identical_splits_and_snapshots()
    {
        // Screen with 60m override (35/65)
        $screenId = $this->screenDurationOverride->id;
        $start = '2026-09-04 10:00:00';
        $end = '2026-09-04 11:00:00';

        // 1. Admin Booking
        Sanctum::actingAs($this->adminUser);
        $adminRes = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screenId], $start, $end));
        $adminRes->assertStatus(201);
        $adminBs = BookingScreen::where('booking_id', $adminRes->json('data.id'))->first();

        // 2. Partner Booking
        Sanctum::actingAs($this->partnerUser);
        $partnerRes = $this->postJson('/api/partner/bookings', [
            'client' => ['name' => 'Cross Test Client', 'email' => 'cross@test.com'],
            'campaign_title' => 'Partner Cross',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-04 12:00:00',
            'end_time' => '2026-09-04 13:00:00',
            'screen_ids' => [$screenId],
            'quantities' => [$screenId => 1],
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'advertisement_category_id' => $this->adCategory->id,
        ]);
        $partnerRes->assertStatus(201);
        $partnerBs = BookingScreen::where('booking_id', $partnerRes->json('data.booking.id'))->first();

        // 3. Customer Booking
        Sanctum::actingAs($this->customerUser);
        $custRes = $this->postJson('/api/bookings/campaign', [
            'campaign_title' => 'Customer Cross',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-04 14:00:00',
            'end_time' => '2026-09-04 15:00:00',
            'screen_ids' => [$screenId],
            'quantities' => [$screenId => 1],
            'total_screens' => 1,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'pricing_plan_id' => $this->minutePlan->id,
            'advertisement_category_id' => $this->adCategory->id,
        ]);
        $custRes->assertSuccessful();
        $custBs = BookingScreen::where('booking_id', $custRes->json('data.id') ?? $custRes->json('data.booking.id'))->first();

        // Assert 100% split consistency across Admin, Partner, and Customer channels
        $this->assertEquals(35.00, (float) $adminBs->illuvision_percentage);
        $this->assertEquals(35.00, (float) $partnerBs->illuvision_percentage);
        $this->assertEquals(35.00, (float) $custBs->illuvision_percentage);

        $this->assertEquals(65.00, (float) $adminBs->screen_provider_percentage);
        $this->assertEquals(65.00, (float) $partnerBs->screen_provider_percentage);
        $this->assertEquals(65.00, (float) $custBs->screen_provider_percentage);

        $this->assertEquals(round($adminBs->gross_revenue * 0.35, 2), (float) $adminBs->platform_share);
        $this->assertEquals(round($partnerBs->gross_revenue * 0.35, 2), (float) $partnerBs->platform_share);
        $this->assertEquals(round($custBs->gross_revenue * 0.35, 2), (float) $custBs->platform_share);

        $this->assertEquals(round($adminBs->gross_revenue * 0.65, 2), (float) $adminBs->provider_share);
        $this->assertEquals(round($partnerBs->gross_revenue * 0.65, 2), (float) $partnerBs->provider_share);
        $this->assertEquals(round($custBs->gross_revenue * 0.65, 2), (float) $custBs->provider_share);
    }

    /** @test */
    public function mixed_fixed_and_mobile_assets_resolve_their_own_independent_splits()
    {
        Sanctum::actingAs($this->adminUser);

        // Booking containing Screen C (Fixed, 60m override 35/65) + Screen A (Fixed Fallback, 40/60)
        $payload = $this->makeBookingPayload(
            [$this->screenDurationOverride->id, $this->screenProviderFallback->id],
            '2026-09-05 10:00:00',
            '2026-09-05 11:00:00',
            [
                'total_screens' => 2,
                'subtotal' => 2000.00,
                'final_amount' => 2000.00,
            ]
        );

        $res = $this->postJson('/api/admin/manual-bookings', $payload);
        $res->assertStatus(201);
        $bookingId = $res->json('data.id');

        $bsDuration = BookingScreen::where('booking_id', $bookingId)
            ->where('screen_id', $this->screenDurationOverride->id)
            ->first();
        $bsFallback = BookingScreen::where('booking_id', $bookingId)
            ->where('screen_id', $this->screenProviderFallback->id)
            ->first();

        // Screen C receives 35/65 (Duration Override)
        $this->assertEquals(35.00, (float) $bsDuration->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bsDuration->screen_provider_percentage);
        $this->assertEquals(round($bsDuration->gross_revenue * 0.35, 2), (float) $bsDuration->platform_share);
        $this->assertEquals(round($bsDuration->gross_revenue * 0.65, 2), (float) $bsDuration->provider_share);

        // Screen A receives 40/60 (Provider Baseline)
        $this->assertEquals(40.00, (float) $bsFallback->illuvision_percentage);
        $this->assertEquals(60.00, (float) $bsFallback->screen_provider_percentage);
        $this->assertEquals(round($bsFallback->gross_revenue * 0.40, 2), (float) $bsFallback->platform_share);
        $this->assertEquals(round($bsFallback->gross_revenue * 0.60, 2), (float) $bsFallback->provider_share);

        // Total check
        $this->assertEquals(
            round($bsDuration->gross_revenue + $bsFallback->gross_revenue, 2),
            round($bsDuration->platform_share + $bsFallback->platform_share + $bsDuration->provider_share + $bsFallback->provider_share, 2)
        );
    }

    /** @test */
    public function direct_illuvision_ownership_preserves_100_0_split_regardless_of_duration()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = $this->makeBookingPayload(
            [$this->screenDirectIlluvision->id],
            '2026-09-06 10:00:00',
            '2026-09-06 11:00:00'
        );

        $res = $this->postJson('/api/admin/manual-bookings', $payload);
        $res->assertStatus(201);
        $bs = BookingScreen::where('booking_id', $res->json('data.id'))->first();

        $this->assertEquals(100.00, (float) $bs->illuvision_percentage);
        $this->assertEquals(0.00, (float) $bs->screen_provider_percentage);
        $this->assertEquals((float) $bs->gross_revenue, (float) $bs->platform_share);
        $this->assertEquals(0.00, (float) $bs->provider_share);
        $this->assertEquals('not_applicable', $bs->settlement_status);
    }

    /** @test */
    public function historical_booking_snapshots_remain_immutable_and_settlement_consumes_stored_snapshots()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Create Booking A (60m @ 35% Illu / 65% Prov)
        $payloadA = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-07 10:00:00',
            '2026-09-07 11:00:00'
        );
        $resA = $this->postJson('/api/admin/manual-bookings', $payloadA);
        $resA->assertStatus(201);
        $bookingAId = $resA->json('data.id');
        $bsA = BookingScreen::where('booking_id', $bookingAId)->first();

        $this->assertEquals(35.00, (float) $bsA->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bsA->screen_provider_percentage);
        $expectedPlatformShareA = round($bsA->gross_revenue * 0.35, 2);
        $expectedProviderShareA = round($bsA->gross_revenue * 0.65, 2);
        $this->assertEquals($expectedPlatformShareA, (float) $bsA->platform_share);
        $this->assertEquals($expectedProviderShareA, (float) $bsA->provider_share);

        // 2. Change duration override configuration for 60m to 20% Illuvision / 80% Provider
        $override = ScreenDurationSplitOverride::where('screen_id', $this->screenDurationOverride->id)
            ->where('pricing_duration_rule_id', $this->rule60m->id)
            ->first();
        $override->update([
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // 3. Verify Booking A snapshot is UNTOUCHED
        $bsAFresh = $bsA->fresh();
        $this->assertEquals(35.00, (float) $bsAFresh->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bsAFresh->screen_provider_percentage);
        $this->assertEquals($expectedPlatformShareA, (float) $bsAFresh->platform_share);
        $this->assertEquals($expectedProviderShareA, (float) $bsAFresh->provider_share);

        // 4. Create Booking B (60m @ new 20% Illu / 80% Prov)
        $payloadB = $this->makeBookingPayload(
            [$this->screenDurationOverride->id],
            '2026-09-07 12:00:00',
            '2026-09-07 13:00:00'
        );
        $resB = $this->postJson('/api/admin/manual-bookings', $payloadB);
        $resB->assertStatus(201);
        $bookingBId = $resB->json('data.id');
        $bsB = BookingScreen::where('booking_id', $bookingBId)->first();

        $this->assertEquals(20.00, (float) $bsB->illuvision_percentage);
        $this->assertEquals(80.00, (float) $bsB->screen_provider_percentage);
        $this->assertEquals(round($bsB->gross_revenue * 0.20, 2), (float) $bsB->platform_share);
        $this->assertEquals(round($bsB->gross_revenue * 0.80, 2), (float) $bsB->provider_share);

        // 5. Generate Settlement for Booking A via SettlementService
        $bookingA = Booking::find($bookingAId);
        $bookingA->update(['status' => Booking::STATUS_COMPLETED]);

        $settlementService = app(SettlementService::class);
        $settlementA = $settlementService->generateSettlement(
            $this->providerAlpha->id,
            '2026-09-01',
            '2026-09-30',
            $this->adminUser,
            $bookingAId
        );

        // Settlement must reflect historical provider share for Booking A, NOT new 80%
        $this->assertEquals($expectedProviderShareA, (float) $settlementA->provider_revenue);
        $this->assertEquals($expectedPlatformShareA, (float) $settlementA->platform_revenue);
    }

    /** @test */
    public function partner_commission_derives_correctly_from_snapshotted_illuvision_platform_share()
    {
        \Illuminate\Support\Facades\Event::fake([
            \App\Support\Events\Notifications\ReferralUsed::class,
            \App\Support\Events\Notifications\CommissionGenerated::class,
            \App\Support\Events\Notifications\BookingCreated::class,
        ]);

        Sanctum::actingAs($this->partnerUser);

        // 1. Create Partner booking with referral code attached (60m @ 35% Illu / 65% Prov)
        $payload = [
            'client' => ['name' => 'Commission Client', 'email' => 'commission@client.com'],
            'campaign_title' => 'Partner Commission Test',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-08 10:00:00',
            'end_time' => '2026-09-08 11:00:00',
            'screen_ids' => [$this->screenDurationOverride->id],
            'quantities' => [$this->screenDurationOverride->id => 1],
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'referral_code_id' => $this->referralCode->id,
            'advertisement_category_id' => $this->adCategory->id,
        ];

        $res = $this->postJson('/api/partner/bookings', $payload);
        $res->assertStatus(201);
        $bookingId = $res->json('data.booking.id');
        $booking = Booking::find($bookingId);

        // Payment record
        $payment = \App\Models\Payment::create([
            'booking_id' => $booking->id,
            'user_id' => $this->partnerUser->id,
            'amount' => $booking->final_amount,
            'currency' => 'INR',
            'status' => 'verified',
            'payment_method' => 'offline',
        ]);

        $trackingService = app(ReferralTrackingService::class);
        $result = $trackingService->createReferralTracking($booking, $payment->id, $this->referralCode);

        $bs = BookingScreen::where('booking_id', $bookingId)->first();
        $expectedPlatformShare = (float) $bs->platform_share;

        // Partner commission rate is 10% on Illuvision Revenue
        $this->assertEquals($expectedPlatformShare, (float) $result['commission']->partner_commission_base);
        $this->assertEquals(round($expectedPlatformShare * 0.10, 2), (float) $result['commission']->amount);
    }
}
