<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\ReferralCode;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use App\Services\Campaign\CurrencyService;
use App\Services\Campaign\ExchangeRateService;
use App\Services\ManualBookingService;
use App\Services\PricingRuleEngine;
use App\Services\Campaign\ReferralTrackingService;
use App\Services\RevenueDistributionService;
use App\Services\RevenueSplitResolverService;
use App\Services\SettlementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialRegressionAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $partnerUser;
    protected User $customerUser;
    protected Partner $partner;
    protected ReferralCode $referralCode;
    protected ScreenProvider $providerAlpha;
    protected ScreenProvider $providerBeta;
    protected PricingPlan $minutePlanA;
    protected PricingPlan $minutePlanB;
    protected PricingDurationRule $rule30to59m;
    protected PricingDurationRule $rule60to89m;
    protected PricingDurationRule $rule90to119m;
    protected PricingDurationRule $rule120to240m;
    protected ScreenCategory $billboardCategory;
    protected AdvertisementCategory $adCategory;
    protected Country $country;
    protected State $state;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            \App\Support\Events\Notifications\ReferralUsed::class,
            \App\Support\Events\Notifications\CommissionGenerated::class,
            \App\Support\Events\Notifications\BookingCreated::class,
        ]);

        // Geography & Category Fixtures
        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->state = State::firstOrCreate(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::firstOrCreate(['name' => 'Kochi', 'state_id' => $this->state->id], ['country_id' => $this->country->id]);
        $this->billboardCategory = ScreenCategory::firstOrCreate(['slug' => 'digital-billboard'], ['name' => 'Digital Billboard']);
        $this->adCategory = AdvertisementCategory::firstOrCreate(['slug' => 'retail'], ['name' => 'Retail & E-commerce', 'status' => 'active']);

        // Users
        $this->adminUser = User::factory()->create(['role' => 'superadmin', 'status' => 'active']);
        $this->customerUser = User::factory()->create(['role' => 'user', 'name' => 'Alice Customer', 'email' => 'alice@customer.com', 'status' => 'active']);
        $this->partnerUser = User::factory()->create(['role' => 'partner', 'name' => 'Bob Partner', 'email' => 'bob@partner.com', 'status' => 'active']);

        // Partner & Referral Code
        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Apex Agency',
            'contact_person' => 'Bob Partner',
            'email' => 'bob@apexagency.com',
            'phone' => '+919876543210',
            'city' => 'Kochi',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $this->referralCode = ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'APEX10',
            'discount_type' => 'percentage',
            'discount_value' => 5.00,
            'commission_type' => 'percentage',
            'commission_value' => 10.00, // 10% on Illuvision Revenue
            'start_date' => now()->subDay(),
            'expiry_date' => now()->addYear(),
            'status' => 'active',
        ]);

        // Screen Providers (Provider Alpha: 60% Prov / 40% Illu; Provider Beta: 80% Prov / 20% Illu)
        $this->providerAlpha = ScreenProvider::create([
            'provider_name' => 'Alpha Media Group',
            'business_name' => 'Alpha Media Ltd',
            'contact_person' => 'Alpha Rep',
            'phone' => '+919999999991',
            'email' => 'alpha@medialtd.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
        ]);

        $this->providerBeta = ScreenProvider::create([
            'provider_name' => 'Beta Media Group',
            'business_name' => 'Beta Media Ltd',
            'contact_person' => 'Beta Rep',
            'phone' => '+919999999992',
            'email' => 'beta@medialtd.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // Pricing Plan A (Minute Plan)
        $this->minutePlanA = PricingPlan::create([
            'name' => 'Minute Plan Alpha',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
            'status' => 'active',
        ]);

        // Pricing Plan B (Minute Plan B)
        $this->minutePlanB = PricingPlan::create([
            'name' => 'Minute Plan Beta',
            'base_price' => 1500.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
            'status' => 'active',
        ]);

        // Duration Rules for Plan A
        $this->rule30to59m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlanA->id,
            'minimum_duration' => 30.00,
            'maximum_duration' => 59.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 0.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->rule60to89m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlanA->id,
            'minimum_duration' => 60.00,
            'maximum_duration' => 89.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 5.00,
            'sort_order' => 2,
            'status' => 'active',
        ]);

        $this->rule90to119m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlanA->id,
            'minimum_duration' => 90.00,
            'maximum_duration' => 119.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 3,
            'status' => 'active',
        ]);

        $this->rule120to240m = PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlanA->id,
            'minimum_duration' => 120.00,
            'maximum_duration' => 240.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 15.00,
            'sort_order' => 4,
            'status' => 'active',
        ]);
    }

    protected function makeBookingPayload(array $screenIds, string $startTime, string $endTime, array $overrides = []): array
    {
        return array_merge([
            'campaign_title' => 'Financial Regression Test Campaign',
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
            'pricing_plan_id' => $this->minutePlanA->id,
            'advertisement_category_id' => $this->adCategory->id,
            'booking_source' => 'admin_manual',
            'client' => [
                'name' => 'Regression Client',
                'email' => 'client.regression@test.com',
                'phone' => '+919876500000',
            ],
        ], $overrides);
    }

    /** @test */
    public function section_3_provider_fallback_resolves_provider_split_when_no_screen_or_duration_override()
    {
        $screen = Screen::create([
            'name' => 'Screen Alpha Baseline',
            'screen_code' => 'SCR-A-01',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Downtown Plaza',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        Sanctum::actingAs($this->adminUser);
        $payload = $this->makeBookingPayload([$screen->id], '2026-09-01 10:00:00', '2026-09-01 10:30:00');
        $res = $this->postJson('/api/admin/manual-bookings', $payload);
        $res->assertStatus(201);

        $bs = BookingScreen::where('booking_id', $res->json('data.id'))->first();
        $this->assertEquals(40.00, (float) $bs->illuvision_percentage);
        $this->assertEquals(60.00, (float) $bs->screen_provider_percentage);
        $this->assertEquals(round($bs->gross_revenue * 0.40, 2), (float) $bs->platform_share);
        $this->assertEquals(round($bs->gross_revenue * 0.60, 2), (float) $bs->provider_share);
    }

    /** @test */
    public function section_4_screen_override_takes_precedence_over_provider_split()
    {
        $screen = Screen::create([
            'name' => 'Screen Beta Custom Split',
            'screen_code' => 'SCR-B-02',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Uptown Mall',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id, // Provider has 60% Prov / 40% Illu
            'illuvision_percentage' => 30.00,                 // Screen override: 70% Prov / 30% Illu
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        Sanctum::actingAs($this->adminUser);
        $payload = $this->makeBookingPayload([$screen->id], '2026-09-01 10:00:00', '2026-09-01 10:30:00');
        $res = $this->postJson('/api/admin/manual-bookings', $payload);
        $res->assertStatus(201);

        $bs = BookingScreen::where('booking_id', $res->json('data.id'))->first();
        $this->assertEquals(30.00, (float) $bs->illuvision_percentage);
        $this->assertEquals(70.00, (float) $bs->screen_provider_percentage);
        $this->assertEquals(round($bs->gross_revenue * 0.30, 2), (float) $bs->platform_share);
        $this->assertEquals(round($bs->gross_revenue * 0.70, 2), (float) $bs->provider_share);
    }

    /** @test */
    public function section_5_and_6_duration_override_and_45m_range_matching_via_pricing_rule_engine()
    {
        $screen = Screen::create([
            'name' => 'Screen Gamma Multi Duration',
            'screen_code' => 'SCR-G-03',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Tech Park',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id, // 60/40 baseline
            'illuvision_percentage' => 30.00,                 // 70/30 screen override
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Configure 60m override -> 65% Prov / 35% Illu
        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule60to89m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // Configure 90m override -> 70% Prov / 30% Illu
        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule90to119m->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->adminUser);

        // 1. Test 30m (30-59 range, no duration override configured -> inherits screen static split 70/30)
        $res30 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], '2026-09-01 10:00:00', '2026-09-01 10:30:00'));
        $res30->assertStatus(201);
        $bs30 = BookingScreen::where('booking_id', $res30->json('data.id'))->first();
        $this->assertEquals(30.00, (float) $bs30->illuvision_percentage);
        $this->assertEquals(70.00, (float) $bs30->screen_provider_percentage);

        // 2. Test 45m (Matches 30-59 rule via PricingRuleEngine -> inherits screen static split 70/30)
        $res45 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], '2026-09-01 11:00:00', '2026-09-01 11:45:00'));
        $res45->assertStatus(201);
        $bs45 = BookingScreen::where('booking_id', $res45->json('data.id'))->first();
        $this->assertEquals(30.00, (float) $bs45->illuvision_percentage);
        $this->assertEquals(70.00, (float) $bs45->screen_provider_percentage);

        // 3. Test 60m (Matches 60-89 rule -> receives configured 65/35 override)
        $res60 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], '2026-09-01 12:00:00', '2026-09-01 13:00:00'));
        $res60->assertStatus(201);
        $bs60 = BookingScreen::where('booking_id', $res60->json('data.id'))->first();
        $this->assertEquals(35.00, (float) $bs60->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bs60->screen_provider_percentage);

        // 4. Test 90m (Matches 90-119 rule -> receives configured 70/30 override)
        $res90 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], '2026-09-01 14:00:00', '2026-09-01 15:30:00'));
        $res90->assertStatus(201);
        $bs90 = BookingScreen::where('booking_id', $res90->json('data.id'))->first();
        $this->assertEquals(30.00, (float) $bs90->illuvision_percentage);
        $this->assertEquals(70.00, (float) $bs90->screen_provider_percentage);
    }

    /** @test */
    public function section_8_and_9_full_cross_channel_matrix_consistency()
    {
        $screen = Screen::create([
            'name' => 'Screen Matrix Consistency',
            'screen_code' => 'SCR-MAT-04',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Metro Terminal',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Set 60m override (35% Illu / 65% Prov)
        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule60to89m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // Set 120m override (25% Illu / 75% Prov)
        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule120to240m->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
            'is_active' => true,
        ]);

        $channels = [
            'admin' => '2026-09-02',
            'partner' => '2026-09-03',
            'customer' => '2026-09-04',
        ];
        $durations = [
            '30m' => ['start' => ' 10:00:00', 'end' => ' 10:30:00', 'expected_illu' => 40.00, 'expected_prov' => 60.00],
            '60m' => ['start' => ' 11:00:00', 'end' => ' 12:00:00', 'expected_illu' => 35.00, 'expected_prov' => 65.00],
            '90m' => ['start' => ' 13:00:00', 'end' => ' 14:30:00', 'expected_illu' => 40.00, 'expected_prov' => 60.00],
            '120m' => ['start' => ' 15:00:00', 'end' => ' 17:00:00', 'expected_illu' => 25.00, 'expected_prov' => 75.00],
        ];

        foreach ($channels as $channel => $datePrefix) {
            foreach ($durations as $label => $spec) {
                $startDt = $datePrefix . $spec['start'];
                $endDt = $datePrefix . $spec['end'];

                if ($channel === 'admin') {
                    Sanctum::actingAs($this->adminUser);
                    $res = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], $startDt, $endDt));
                    $res->assertStatus(201);
                    $bookingId = $res->json('data.id');
                } elseif ($channel === 'partner') {
                    Sanctum::actingAs($this->partnerUser);
                    $res = $this->postJson('/api/partner/bookings', [
                        'client' => ['name' => "Partner Client {$label}", 'email' => "partner.{$label}@test.com"],
                        'campaign_title' => "Partner Campaign {$label}",
                        'ad_frequency' => 'every_5_min',
                        'start_time' => $startDt,
                        'end_time' => $endDt,
                        'screen_ids' => [$screen->id],
                        'quantities' => [$screen->id => 1],
                        'subtotal' => 1000.00,
                        'final_amount' => 1000.00,
                        'advertisement_category_id' => $this->adCategory->id,
                    ]);
                    $res->assertStatus(201);
                    $bookingId = $res->json('data.booking.id');
                } else {
                    Sanctum::actingAs($this->customerUser);
                    $res = $this->postJson('/api/bookings/campaign', [
                        'campaign_title' => "Customer Campaign {$label}",
                        'ad_frequency' => 'every_5_min',
                        'start_time' => $startDt,
                        'end_time' => $endDt,
                        'screen_ids' => [$screen->id],
                        'quantities' => [$screen->id => 1],
                        'total_screens' => 1,
                        'subtotal' => 1000.00,
                        'final_amount' => 1000.00,
                        'pricing_plan_id' => $this->minutePlanA->id,
                        'advertisement_category_id' => $this->adCategory->id,
                    ]);
                    $res->assertSuccessful();
                    $bookingId = $res->json('data.id') ?? $res->json('data.booking.id');
                }

                $bs = BookingScreen::where('booking_id', $bookingId)->first();
                $this->assertEquals($spec['expected_illu'], (float) $bs->illuvision_percentage, "Mismatch in {$channel} at {$label} for illuvision_percentage");
                $this->assertEquals($spec['expected_prov'], (float) $bs->screen_provider_percentage, "Mismatch in {$channel} at {$label} for screen_provider_percentage");
                $this->assertEquals(round($bs->gross_revenue * ($spec['expected_illu'] / 100), 2), (float) $bs->platform_share);
                $this->assertEquals(round($bs->gross_revenue * ($spec['expected_prov'] / 100), 2), (float) $bs->provider_share);
            }
        }
    }

    /** @test */
    public function section_15_and_16_currency_invariance_and_canonical_inr_basis()
    {
        $screen = Screen::create([
            'name' => 'Screen Currency Invariant',
            'screen_code' => 'SCR-CURR-05',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Airport Boulevard',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule60to89m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->adminUser);
        $res = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen->id], '2026-09-03 10:00:00', '2026-09-03 11:00:00'));
        $res->assertStatus(201);
        $booking = Booking::find($res->json('data.id'));
        $bs = $booking->bookingScreens()->first();

        // Stored ledger values are in canonical INR
        $this->assertEquals(35.00, (float) $bs->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bs->screen_provider_percentage);

        // Display currency conversions (USD, EUR, GBP, AED)
        $exchangeService = app(ExchangeRateService::class);
        $usdGross = $exchangeService->convertToDisplay((float) $bs->gross_revenue, 'USD');
        $eurGross = $exchangeService->convertToDisplay((float) $bs->gross_revenue, 'EUR');

        $this->assertGreaterThan(0, $usdGross);
        $this->assertGreaterThan(0, $eurGross);

        // Split percentages remain 100% immutable regardless of display currency
        $this->assertEquals(35.00, (float) $bs->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bs->screen_provider_percentage);
    }

    /** @test */
    public function section_32_to_35_duration_plan_screen_and_provider_isolation()
    {
        // Screen 1 (Provider Alpha, Plan A, 60m override 35/65)
        $screen1 = Screen::create([
            'name' => 'Screen Isolation 1',
            'screen_code' => 'SCR-ISO-01',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Site 1',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen1->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule60to89m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // Screen 2 (Provider Beta, Plan A, No override -> inherits Provider Beta 20/80)
        $screen2 = Screen::create([
            'name' => 'Screen Isolation 2',
            'screen_code' => 'SCR-ISO-02',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Site 2',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerBeta->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        Sanctum::actingAs($this->adminUser);

        // Booking 1 on Screen 1 -> gets 35/65
        $res1 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen1->id], '2026-09-04 10:00:00', '2026-09-04 11:00:00'));
        $res1->assertStatus(201);
        $bs1 = BookingScreen::where('booking_id', $res1->json('data.id'))->first();
        $this->assertEquals(35.00, (float) $bs1->illuvision_percentage);
        $this->assertEquals(65.00, (float) $bs1->screen_provider_percentage);

        // Booking 2 on Screen 2 -> gets 20/80 (Provider Beta baseline, no leakage from Screen 1)
        $res2 = $this->postJson('/api/admin/manual-bookings', $this->makeBookingPayload([$screen2->id], '2026-09-04 10:00:00', '2026-09-04 11:00:00'));
        $res2->assertStatus(201);
        $bs2 = BookingScreen::where('booking_id', $res2->json('data.id'))->first();
        $this->assertEquals(20.00, (float) $bs2->illuvision_percentage);
        $this->assertEquals(80.00, (float) $bs2->screen_provider_percentage);
    }

    /** @test */
    public function section_39_to_41_rounding_precision_large_amounts_and_edge_cases()
    {
        $screen = Screen::create([
            'name' => 'Screen Financial Precision',
            'screen_code' => 'SCR-PREC-06',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Precision Site',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 33.33,
            'screen_provider_percentage' => 66.67,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        Sanctum::actingAs($this->adminUser);

        // Large amount test (₹1,000,000)
        $payloadLarge = $this->makeBookingPayload([$screen->id], '2026-09-05 10:00:00', '2026-09-05 10:30:00', [
            'subtotal' => 1000000.00,
            'final_amount' => 1000000.00,
        ]);
        $resLarge = $this->postJson('/api/admin/manual-bookings', $payloadLarge);
        $resLarge->assertStatus(201);
        $bsLarge = BookingScreen::where('booking_id', $resLarge->json('data.id'))->first();

        // Assert no overflow or precision loss
        $this->assertEquals(33.33, (float) $bsLarge->illuvision_percentage);
        $this->assertEquals(66.67, (float) $bsLarge->screen_provider_percentage);
        $this->assertEquals(round((float) $bsLarge->gross_revenue * 0.3333, 2), (float) $bsLarge->platform_share);
        $this->assertEquals(round((float) $bsLarge->gross_revenue * 0.6667, 2), (float) $bsLarge->provider_share);
        $this->assertEquals(
            round((float) $bsLarge->gross_revenue, 2),
            round((float) $bsLarge->platform_share + (float) $bsLarge->provider_share, 2)
        );
    }

    /** @test */
    public function section_58_defensive_percentage_validation_fails_safely_on_corrupt_data()
    {
        $screen = Screen::create([
            'name' => 'Screen Defensive Check',
            'screen_code' => 'SCR-DEF-07',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Defensive Site',
            'pricing_plan_id' => $this->minutePlanA->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Insert corrupt duration override directly into DB (sums to 110%)
        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->minutePlanA->id,
            'pricing_duration_rule_id' => $this->rule60to89m->id,
            'illuvision_percentage' => 50.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        $resolver = app(RevenueSplitResolverService::class);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Percentages do not sum to 100%');

        $resolver->resolveEffectiveSplit($screen, $this->minutePlanA, $this->rule60to89m);
    }
}
