<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDisplaySplitAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $providerAlphaUser;
    protected User $providerBetaUser;
    protected ScreenProvider $providerAlpha;
    protected ScreenProvider $providerBeta;
    protected PricingPlan $minutePlan;
    protected PricingDurationRule $rule30m;
    protected PricingDurationRule $rule60m;
    protected PricingDurationRule $rule90m;
    protected PricingDurationRule $rule120m;
    protected ScreenCategory $billboardCategory;
    protected AdvertisementCategory $adCategory;
    protected Country $country;
    protected State $state;
    protected City $city;

    protected Screen $screenDurationOverride;
    protected Screen $screenDirectIlluvision;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            \App\Support\Events\Notifications\BookingCreated::class,
        ]);

        // 1. Seed custom Role and Permissions
        $this->seed(\Database\Seeders\ScreenProviderRoleSeeder::class);

        // 2. Geography & Category Fixtures
        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->state = State::firstOrCreate(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::firstOrCreate(['name' => 'Kochi', 'state_id' => $this->state->id], ['country_id' => $this->country->id]);
        $this->billboardCategory = ScreenCategory::firstOrCreate(['slug' => 'digital-billboard'], ['name' => 'Digital Billboard']);
        $this->adCategory = AdvertisementCategory::firstOrCreate(['slug' => 'retail'], ['name' => 'Retail & E-commerce', 'status' => 'active']);

        // 3. Users & Roles
        $this->adminUser = User::factory()->create(['role' => 'superadmin', 'status' => 'active']);
        $this->providerAlphaUser = User::factory()->create(['role' => 'screen_provider', 'name' => 'Alpha Owner', 'email' => 'alpha@owner.com', 'status' => 'active']);
        $this->providerBetaUser = User::factory()->create(['role' => 'screen_provider', 'name' => 'Beta Owner', 'email' => 'beta@owner.com', 'status' => 'active']);

        // Screen Providers (Alpha: 60% Prov / 40% Illu; Beta: 80% Prov / 20% Illu)
        $this->providerAlpha = ScreenProvider::create([
            'user_id' => $this->providerAlphaUser->id,
            'provider_name' => 'Alpha Media Group',
            'business_name' => 'Alpha Media Ltd',
            'contact_person' => 'Alpha Rep',
            'phone' => '+919999999991',
            'email' => 'alpha@owner.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
        ]);

        $this->providerBeta = ScreenProvider::create([
            'user_id' => $this->providerBetaUser->id,
            'provider_name' => 'Beta Media Group',
            'business_name' => 'Beta Media Ltd',
            'contact_person' => 'Beta Rep',
            'phone' => '+919999999992',
            'email' => 'beta@owner.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // Pricing Plan & Rules
        $this->minutePlan = PricingPlan::create([
            'name' => 'Display Audit Minute Plan',
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

        // Screens
        $this->screenDurationOverride = Screen::create([
            'name' => 'Screen Alpha Commercial',
            'screen_code' => 'SCR-DISP-01',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'City Center',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00, // Screen static split: 70/30
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // Duration Override: 60m -> 35% Illu / 65% Prov
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule60m->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // Duration Override: 120m -> 25% Illu / 75% Prov
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenDurationOverride->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'pricing_duration_rule_id' => $this->rule120m->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
            'is_active' => true,
        ]);

        // Direct Illuvision Screen
        $this->screenDirectIlluvision = Screen::create([
            'name' => 'Direct Illuvision Display',
            'screen_code' => 'SCR-ILLU-DISP',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->billboardCategory->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Illuvision Hub',
            'pricing_plan_id' => $this->minutePlan->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    protected function makeBooking(array $screenIds, string $startTime, string $endTime): Booking
    {
        Sanctum::actingAs($this->adminUser);
        $payload = [
            'campaign_title' => 'Display Audit Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'daily_start_time' => date('H:i', strtotime($startTime)),
            'daily_end_time' => date('H:i', strtotime($endTime)),
            'screen_ids' => $screenIds,
            'total_screens' => count($screenIds),
            'quantities' => array_fill_keys($screenIds, 1),
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'pricing_plan_id' => $this->minutePlan->id,
            'advertisement_category_id' => $this->adCategory->id,
            'booking_source' => 'admin_manual',
            'client' => [
                'name' => 'Display Audit Client',
                'email' => 'client@displayaudit.com',
                'phone' => '+919876543210',
            ],
        ];

        $res = $this->postJson('/api/admin/manual-bookings', $payload);
        $res->assertStatus(201);

        return Booking::find($res->json('data.id'));
    }

    /** @test */
    public function admin_and_provider_portal_display_matching_snapshotted_split_for_duration_override()
    {
        // 1. Create a 60m booking (Duration override: 35% Illu / 65% Prov)
        $booking = $this->makeBooking([$this->screenDurationOverride->id], '2026-09-01 10:00:00', '2026-09-01 11:00:00');

        // 2. Fetch Admin View (GET /api/bookings/{id})
        Sanctum::actingAs($this->adminUser);
        $adminRes = $this->getJson("/api/bookings/{$booking->id}");
        $adminRes->assertStatus(200);

        $adminBookingScreens = $adminRes->json('data.booking_screens');
        $this->assertNotEmpty($adminBookingScreens);
        $adminBs = $adminBookingScreens[0];

        $this->assertEquals(35.00, (float) $adminBs['illuvision_percentage']);
        $this->assertEquals(65.00, (float) $adminBs['screen_provider_percentage']);
        $this->assertEquals(round($adminBs['gross_revenue'] * 0.35, 2), (float) $adminBs['platform_share']);
        $this->assertEquals(round($adminBs['gross_revenue'] * 0.65, 2), (float) $adminBs['provider_share']);

        // 3. Fetch Provider View (GET /api/screen-provider/campaigns/{id})
        Sanctum::actingAs($this->providerAlphaUser);
        $providerRes = $this->getJson("/api/screen-provider/campaigns/{$booking->id}");
        $providerRes->assertStatus(200);

        $providerScreens = $providerRes->json('data.screens');
        $this->assertNotEmpty($providerScreens);
        $provBs = $providerScreens[0];

        // Provider sees their authorized revenue share & percentage matching the exact database snapshot
        $this->assertEquals(65.00, (float) $provBs['provider_percentage']);
        $this->assertEquals((float) $adminBs['provider_share'], (float) $provBs['provider_share']);
        $this->assertEquals((float) $adminBs['gross_revenue'], (float) $provBs['gross_revenue']);
    }

    /** @test */
    public function historical_booking_display_remains_immutable_when_current_duration_split_is_updated()
    {
        // 1. Create Booking A (60m @ 65% Prov / 35% Illu)
        $bookingA = $this->makeBooking([$this->screenDurationOverride->id], '2026-09-02 10:00:00', '2026-09-02 11:00:00');

        // 2. Admin updates the 60m override to 80% Provider / 20% Illuvision
        $override = ScreenDurationSplitOverride::where('screen_id', $this->screenDurationOverride->id)
            ->where('pricing_duration_rule_id', $this->rule60m->id)
            ->first();
        $override->update([
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // 3. Query Booking A via Admin & Provider views -> MUST STILL SHOW 65/35
        Sanctum::actingAs($this->adminUser);
        $adminResA = $this->getJson("/api/bookings/{$bookingA->id}");
        $adminResA->assertStatus(200);
        $this->assertEquals(65.00, (float) $adminResA->json('data.booking_screens.0.screen_provider_percentage'));
        $this->assertEquals(35.00, (float) $adminResA->json('data.booking_screens.0.illuvision_percentage'));

        Sanctum::actingAs($this->providerAlphaUser);
        $provResA = $this->getJson("/api/screen-provider/campaigns/{$bookingA->id}");
        $provResA->assertStatus(200);
        $this->assertEquals(65.00, (float) $provResA->json('data.screens.0.provider_percentage'));

        // 4. Create Booking B (60m @ new 80% Prov / 20% Illu)
        $bookingB = $this->makeBooking([$this->screenDurationOverride->id], '2026-09-02 12:00:00', '2026-09-02 13:00:00');

        // 5. Query Booking B via Admin & Provider views -> MUST SHOW NEW 80/20
        Sanctum::actingAs($this->adminUser);
        $adminResB = $this->getJson("/api/bookings/{$bookingB->id}");
        $adminResB->assertStatus(200);
        $this->assertEquals(80.00, (float) $adminResB->json('data.booking_screens.0.screen_provider_percentage'));
        $this->assertEquals(20.00, (float) $adminResB->json('data.booking_screens.0.illuvision_percentage'));

        Sanctum::actingAs($this->providerAlphaUser);
        $provResB = $this->getJson("/api/screen-provider/campaigns/{$bookingB->id}");
        $provResB->assertStatus(200);
        $this->assertEquals(80.00, (float) $provResB->json('data.screens.0.provider_percentage'));
    }

    /** @test */
    public function provider_access_control_prevents_unauthorized_cross_provider_financial_visibility()
    {
        // Booking belongs to Provider Alpha
        $bookingAlpha = $this->makeBooking([$this->screenDurationOverride->id], '2026-09-03 10:00:00', '2026-09-03 11:00:00');

        // Provider Beta attempts to access Provider Alpha's booking details
        Sanctum::actingAs($this->providerBetaUser);
        $response = $this->getJson("/api/screen-provider/campaigns/{$bookingAlpha->id}");

        // Must be rejected with 403 Forbidden
        $response->assertStatus(403);
    }

    /** @test */
    public function direct_illuvision_ownership_displays_100_0_split_in_admin_view()
    {
        $bookingDirect = $this->makeBooking([$this->screenDirectIlluvision->id], '2026-09-04 10:00:00', '2026-09-04 11:00:00');

        Sanctum::actingAs($this->adminUser);
        $adminRes = $this->getJson("/api/bookings/{$bookingDirect->id}");
        $adminRes->assertStatus(200);

        $bs = $adminRes->json('data.booking_screens.0');
        $this->assertEquals(100.00, (float) $bs['illuvision_percentage']);
        $this->assertEquals(0.00, (float) $bs['screen_provider_percentage']);
        $this->assertEquals((float) $bs['gross_revenue'], (float) $bs['platform_share']);
        $this->assertEquals(0.00, (float) $bs['provider_share']);
        $this->assertEquals('not_applicable', $bs['settlement_status']);
    }
}
