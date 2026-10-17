<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingPricingBreakdown;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ScreenProvider;
use App\Models\User;
use App\Services\RevenueDistributionService;
use App\Services\RevenueSplitResolverService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueSplitResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RevenueSplitResolverService $resolver;
    protected RevenueDistributionService $distributionService;

    protected User $user;
    protected ScreenProvider $providerA;
    protected ScreenProvider $providerB;
    protected PricingPlan $planA;
    protected PricingPlan $planB;
    protected PricingDurationRule $ruleA30;
    protected PricingDurationRule $ruleA60;
    protected PricingDurationRule $ruleA90;
    protected PricingDurationRule $ruleB30;
    protected Screen $screen101;
    protected Screen $screenDirect;
    protected ScreenCategory $category;
    protected AdvertisementCategory $adCategory;
    protected Country $country;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RevenueSplitResolverService::class);
        $this->distributionService = app(RevenueDistributionService::class);

        $this->user = User::factory()->create();
        $this->country = Country::create(['name' => 'India', 'code' => 'IN']);
        $this->city = City::create(['name' => 'Kochi', 'country_id' => $this->country->id]);
        $this->category = ScreenCategory::create(['name' => 'Digital Billboard', 'slug' => 'digital-billboard']);
        $this->adCategory = AdvertisementCategory::create(['name' => 'Tech', 'slug' => 'tech', 'status' => 'active']);

        // 1. Providers
        $this->providerA = ScreenProvider::create([
            'provider_name' => 'Provider A',
            'business_name' => 'Alpha Media',
            'contact_person' => 'Alice',
            'phone' => '+919999999901',
            'email' => 'alpha@test.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
        ]);

        $this->providerB = ScreenProvider::create([
            'provider_name' => 'Provider B',
            'business_name' => 'Beta Media',
            'contact_person' => 'Bob',
            'phone' => '+919999999902',
            'email' => 'beta@test.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
        ]);

        // 2. Pricing Plans
        $this->planA = PricingPlan::create([
            'name' => 'Business Growth',
            'base_price' => 100.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        $this->planB = PricingPlan::create([
            'name' => 'Enterprise Plan',
            'base_price' => 200.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        // 3. Duration Rules
        $this->ruleA30 = PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 30.00,
            'maximum_duration' => 59.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 0.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->ruleA60 = PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 60.00,
            'maximum_duration' => 89.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 5.00,
            'sort_order' => 2,
            'status' => 'active',
        ]);

        $this->ruleA90 = PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 90.00,
            'maximum_duration' => 119.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 3,
            'status' => 'active',
        ]);

        $this->ruleB30 = PricingDurationRule::create([
            'pricing_plan_id' => $this->planB->id,
            'minimum_duration' => 30.00,
            'maximum_duration' => 59.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 0.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        // 4. Screens
        $this->screen101 = Screen::create([
            'name' => 'Screen 101',
            'screen_code' => 'SCR-101',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Downtown Central',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => $this->planA->id,
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->screenDirect = Screen::create([
            'name' => 'Direct Illuvision Screen',
            'screen_code' => 'SCR-ILLU-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Illuvision HQ',
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'pricing_plan_id' => $this->planA->id,
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    /** @test */
    public function scenario_a_resolves_provider_split_when_no_screen_or_duration_override_exists()
    {
        $screen = new Screen([
            'name' => 'Screen Inheriting Provider',
            'screen_code' => 'SCR-PROV-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'North Junction',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => $this->planA->id,
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'illuvision_percentage' => null,
            'screen_provider_percentage' => null,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $screen->setRelation('screenProvider', $this->providerA);

        $result = $this->resolver->resolveEffectiveSplit($screen, $this->planA, null);

        $this->assertEquals(40.00, $result['illuvision_percentage']);
        $this->assertEquals(60.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_PROVIDER_SPLIT, $result['resolution_source']);
        $this->assertFalse($result['is_override_applied']);
    }

    /** @test */
    public function scenario_b_resolves_screen_static_override_over_provider_split()
    {
        $result = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, null);

        $this->assertEquals(30.00, $result['illuvision_percentage']);
        $this->assertEquals(70.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SCREEN_OVERRIDE, $result['resolution_source']);
        $this->assertTrue($result['is_override_applied']);
    }

    /** @test */
    public function scenario_c_resolves_duration_override_over_screen_and_provider_split()
    {
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        $result = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA30);

        $this->assertEquals(35.00, $result['illuvision_percentage']);
        $this->assertEquals(65.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_DURATION_OVERRIDE, $result['resolution_source']);
        $this->assertEquals($this->ruleA30->id, $result['pricing_duration_rule_id']);
        $this->assertTrue($result['is_override_applied']);
    }

    /** @test */
    public function scenario_d_direct_illuvision_ownership_takes_absolute_precedence()
    {
        $result = $this->resolver->resolveEffectiveSplit($this->screenDirect, $this->planA, $this->ruleA30);

        $this->assertEquals(100.00, $result['illuvision_percentage']);
        $this->assertEquals(0.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_DIRECT_ILLUVISION, $result['resolution_source']);
        $this->assertFalse($result['is_override_applied']);
    }

    /** @test */
    public function scenario_e_resolves_system_default_when_no_configuration_exists()
    {
        $screenNoConfig = new Screen([
            'name' => 'Screen No Config',
            'screen_code' => 'SCR-NONE-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Empty Site',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => null,
            'pricing_plan_id' => null,
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'illuvision_percentage' => null,
            'screen_provider_percentage' => null,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $result = $this->resolver->resolveEffectiveSplit($screenNoConfig, null, null);

        $this->assertEquals(10.00, $result['illuvision_percentage']);
        $this->assertEquals(90.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SYSTEM_DEFAULT, $result['resolution_source']);
    }

    /** @test */
    public function inactive_duration_override_falls_through_to_screen_split()
    {
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => false, // Inactive
        ]);

        $result = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA30);

        $this->assertEquals(30.00, $result['illuvision_percentage']);
        $this->assertEquals(70.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SCREEN_OVERRIDE, $result['resolution_source']);
    }

    /** @test */
    public function cross_plan_duration_rule_mismatch_is_prevented()
    {
        // Rule B30 belongs to Plan B, but we query with Plan A
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planB->id,
            'pricing_duration_rule_id' => $this->ruleB30->id,
            'illuvision_percentage' => 50.00,
            'screen_provider_percentage' => 50.00,
            'is_active' => true,
        ]);

        // Attempt resolving with Plan A + Rule B30
        $result = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleB30);

        // Must safely ignore Rule B30 and fall through to Screen 101 static split (30/70)
        $this->assertEquals(30.00, $result['illuvision_percentage']);
        $this->assertEquals(70.00, $result['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SCREEN_OVERRIDE, $result['resolution_source']);
    }

    /** @test */
    public function section_59_full_end_to_end_resolution_matrix()
    {
        // Setup Section 59 Matrix:
        // Provider A: 40% Illuvision / 60% Provider
        // Screen 101: 30% Illuvision / 70% Provider
        // 30m Rule: 40% Illuvision / 60% Provider
        // 60m Rule: 35% Illuvision / 65% Provider
        // 90m Rule: 30% Illuvision / 70% Provider

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        $override60 = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA90->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'is_active' => true,
        ]);

        // 1. 30-min booking -> 40 / 60
        $res30 = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA30);
        $this->assertEquals(40.00, $res30['illuvision_percentage']);
        $this->assertEquals(60.00, $res30['screen_provider_percentage']);

        // 2. 60-min booking -> 35 / 65
        $res60 = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA60);
        $this->assertEquals(35.00, $res60['illuvision_percentage']);
        $this->assertEquals(65.00, $res60['screen_provider_percentage']);

        // 3. 90-min booking -> 30 / 70
        $res90 = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA90);
        $this->assertEquals(30.00, $res90['illuvision_percentage']);
        $this->assertEquals(70.00, $res90['screen_provider_percentage']);

        // 4. Disable 60m override -> falls back to Screen Split (30/70)
        $override60->update(['is_active' => false]);
        $res60Disabled = $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA60);
        $this->assertEquals(30.00, $res60Disabled['illuvision_percentage']);
        $this->assertEquals(70.00, $res60Disabled['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SCREEN_OVERRIDE, $res60Disabled['resolution_source']);

        // 5. If Screen Split is removed (null) but 60m is active again -> uses 60m override (35/65)
        $override60->update(['is_active' => true]);
        $screenNoStatic = new Screen([
            'name' => 'Screen 101 No Static',
            'screen_code' => 'SCR-101-NS',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Downtown Central',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => $this->planA->id,
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'illuvision_percentage' => null,
            'screen_provider_percentage' => null,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $screenNoStatic->id = $this->screen101->id;
        $screenNoStatic->setRelation('screenProvider', $this->providerA);

        $res60NoScreen = $this->resolver->resolveEffectiveSplit($screenNoStatic, $this->planA, $this->ruleA60);
        $this->assertEquals(35.00, $res60NoScreen['illuvision_percentage']);
        $this->assertEquals(65.00, $res60NoScreen['screen_provider_percentage']);

        // 6. If Duration Split and Screen Split are removed -> falls back to Provider Split (40/60)
        $override60->update(['is_active' => false]);
        $res60NoDurNoScreen = $this->resolver->resolveEffectiveSplit($screenNoStatic, $this->planA, $this->ruleA60);
        $this->assertEquals(40.00, $res60NoDurNoScreen['illuvision_percentage']);
        $this->assertEquals(60.00, $res60NoDurNoScreen['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_PROVIDER_SPLIT, $res60NoDurNoScreen['resolution_source']);

        // 7. If Provider Split is removed -> falls back to System Default (10/90)
        $providerNoSplit = new ScreenProvider([
            'provider_name' => 'Provider No Split',
            'illuvision_percentage' => null,
            'screen_provider_percentage' => null,
        ]);
        $screenNoStatic->setRelation('screenProvider', $providerNoSplit);
        $resDefault = $this->resolver->resolveEffectiveSplit($screenNoStatic, $this->planA, $this->ruleA60);
        $this->assertEquals(10.00, $resDefault['illuvision_percentage']);
        $this->assertEquals(90.00, $resDefault['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SYSTEM_DEFAULT, $resDefault['resolution_source']);

        // 8. Direct Illuvision ownership -> 100/0
        $resDirect = $this->resolver->resolveEffectiveSplit($this->screenDirect, $this->planA, $this->ruleA60);
        $this->assertEquals(100.00, $resDirect['illuvision_percentage']);
        $this->assertEquals(0.00, $resDirect['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_DIRECT_ILLUVISION, $resDirect['resolution_source']);
    }

    /** @test */
    public function defensive_percentage_validation_fails_safely_on_corrupt_data()
    {
        $this->expectException(DomainException::class);

        // Manually create invalid override via DB (bypassing model rules)
        $override = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 60.00,
            'screen_provider_percentage' => 50.00, // Sum = 110%
            'is_active' => true,
        ]);

        $this->resolver->resolveEffectiveSplit($this->screen101, $this->planA, $this->ruleA30);
    }

    /** @test */
    public function revenue_distribution_service_processes_duration_split_snapshots_correctly()
    {
        // 1. Create Duration Override for Screen 101 @ 35% Illuvision / 65% Provider
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // 2. Create Booking for 30 minutes (10:00 to 10:30)
        $booking = Booking::create([
            'user_id' => $this->user->id,
            'title' => '30 Min Test Campaign',
            'campaign_title' => '30 Min Test Campaign',
            'advertiser_name' => 'Acme Corp',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-01 10:00:00',
            'end_time' => '2026-09-01 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'total_screens' => 1,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'status' => 'approved',
            'source' => 'website',
            'advertisement_category_id' => $this->adCategory->id,
        ]);

        $bookingScreen = BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->screen101->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'subtotal' => 1000.00,
            'gross_revenue' => 1000.00,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'screen_provider_name' => $this->providerA->provider_name,
            'status' => 'pending',
        ]);

        // 3. Process Revenue Distribution
        $this->distributionService->processBookingScreens($booking);

        // 4. Verify snapshotted shares on BookingScreen
        $refreshed = $bookingScreen->fresh();
        $this->assertEquals(35.00, (float) $refreshed->illuvision_percentage);
        $this->assertEquals(65.00, (float) $refreshed->screen_provider_percentage);
        $this->assertEquals(350.00, (float) $refreshed->platform_share);
        $this->assertEquals(650.00, (float) $refreshed->provider_share);
        $this->assertEquals('pending', $refreshed->settlement_status);
    }

    /** @test */
    public function historical_booking_snapshots_remain_immutable_when_split_configuration_changes()
    {
        // 1. Create Override @ 40 / 60
        $override = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screen101->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        // 2. Create Booking A
        $bookingA = Booking::create([
            'user_id' => $this->user->id,
            'title' => 'Historical Booking A',
            'campaign_title' => 'Historical Booking A',
            'advertiser_name' => 'Acme Corp',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-01 10:00:00',
            'end_time' => '2026-09-01 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'total_screens' => 1,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'status' => 'approved',
            'source' => 'website',
            'advertisement_category_id' => $this->adCategory->id,
        ]);

        $bookingScreenA = BookingScreen::create([
            'booking_id' => $bookingA->id,
            'screen_id' => $this->screen101->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'subtotal' => 1000.00,
            'gross_revenue' => 1000.00,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'status' => 'pending',
        ]);

        $this->distributionService->processBookingScreens($bookingA);

        // Booking A is frozen at 40 / 60 (Platform: 400, Provider: 600)
        $this->assertEquals(400.00, (float) $bookingScreenA->fresh()->platform_share);
        $this->assertEquals(600.00, (float) $bookingScreenA->fresh()->provider_share);

        // 3. Administrator changes split configuration on 30m rule to 30 / 70
        $override->update([
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
        ]);

        // 4. Create Booking B under new configuration
        $bookingB = Booking::create([
            'user_id' => $this->user->id,
            'title' => 'New Booking B',
            'campaign_title' => 'New Booking B',
            'advertiser_name' => 'Beta Corp',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-09-02 10:00:00',
            'end_time' => '2026-09-02 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'total_screens' => 1,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'status' => 'approved',
            'source' => 'website',
            'advertisement_category_id' => $this->adCategory->id,
        ]);

        $bookingScreenB = BookingScreen::create([
            'booking_id' => $bookingB->id,
            'screen_id' => $this->screen101->id,
            'city_id' => $this->city->id,
            'quantity' => 1,
            'subtotal' => 1000.00,
            'gross_revenue' => 1000.00,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'status' => 'pending',
        ]);

        $this->distributionService->processBookingScreens($bookingB);

        // 5. Verify Booking A remains 100% UNTOUCHED (Historical Safety)
        $this->assertEquals(400.00, (float) $bookingScreenA->fresh()->platform_share);
        $this->assertEquals(600.00, (float) $bookingScreenA->fresh()->provider_share);
        $this->assertEquals(40.00, (float) $bookingScreenA->fresh()->illuvision_percentage);

        // 6. Verify Booking B uses new 30 / 70 snapshot (Platform: 300, Provider: 700)
        $this->assertEquals(300.00, (float) $bookingScreenB->fresh()->platform_share);
        $this->assertEquals(700.00, (float) $bookingScreenB->fresh()->provider_share);
        $this->assertEquals(30.00, (float) $bookingScreenB->fresh()->illuvision_percentage);
    }
}
