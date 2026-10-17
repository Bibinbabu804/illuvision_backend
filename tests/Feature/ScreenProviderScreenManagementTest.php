<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ScreenProvider;
use App\Models\Screen;
use App\Models\Country;
use App\Models\City;
use App\Models\State;
use App\Models\PricingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenProviderScreenManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $providerUser;
    private ScreenProvider $provider;
    private User $otherProviderUser;
    private ScreenProvider $otherProvider;
    private Country $country;
    private City $city;
    private PricingPlan $pricingPlan;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed custom Role and Permissions
        $this->seed(\Database\Seeders\ScreenProviderRoleSeeder::class);

        // 2. Create Countries, Cities, Pricing Plans
        $this->country = Country::firstOrCreate(['name' => 'India'], [
            'code' => 'IN',
            'is_active' => true
        ]);

        $this->city = City::firstOrCreate(['name' => 'Kochi', 'country_id' => $this->country->id], [
            'is_active' => true
        ]);

        $this->pricingPlan = PricingPlan::create([
            'name' => 'Standard Plan',
            'base_price' => 500,
            'currency' => 'INR',
            'is_active' => true
        ]);

        // 3. Create Screen Provider A
        $this->providerUser = User::create([
            'name' => 'Provider Admin A',
            'email' => 'providera@example.com',
            'password' => bcrypt('password'),
            'role' => 'screen_provider',
            'status' => 'active',
        ]);

        $this->provider = ScreenProvider::create([
            'user_id' => $this->providerUser->id,
            'provider_name' => 'Provider A Corp',
            'email' => 'providera@example.com',
            'phone' => '9999999991',
            'status' => ScreenProvider::STATUS_APPROVED,
        ]);

        // 4. Create Screen Provider B
        $this->otherProviderUser = User::create([
            'name' => 'Provider Admin B',
            'email' => 'providerb@example.com',
            'password' => bcrypt('password'),
            'role' => 'screen_provider',
            'status' => 'active',
        ]);

        $this->otherProvider = ScreenProvider::create([
            'user_id' => $this->otherProviderUser->id,
            'provider_name' => 'Provider B Corp',
            'email' => 'providerb@example.com',
            'phone' => '9999999992',
            'status' => ScreenProvider::STATUS_APPROVED,
        ]);
    }

    public function test_provider_can_only_list_their_own_screens()
    {
        // Screen A owned by Provider A
        $screenA = Screen::create([
            'name' => 'Screen A1',
            'screen_code' => 'SCR-A1',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Lulu Mall',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        // Screen B owned by Provider B
        $screenB = Screen::create([
            'name' => 'Screen B1',
            'screen_code' => 'SCR-B1',
            'screen_type' => 'Outdoor Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->otherProvider->id,
        ]);

        // Act: Request screens as Provider A
        $response = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonFragment(['name' => 'Screen A1']);
        $response->assertJsonMissing(['name' => 'Screen B1']);
    }

    public function test_provider_can_filter_screens()
    {
        // Screen 1: active, type=LED Video Wall
        Screen::create([
            'name' => 'Active LED',
            'screen_code' => 'SCR-1',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Lulu Mall',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        // Screen 2: maintenance, type=Outdoor Billboard
        Screen::create([
            'name' => 'Maintenance Billboard',
            'screen_code' => 'SCR-2',
            'screen_type' => 'Outdoor Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'maintenance',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        // Filter by status=maintenance
        $response1 = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens?status=maintenance');
        $response1->assertStatus(200);
        $response1->assertJsonPath('meta.total', 1);
        $response1->assertJsonFragment(['name' => 'Maintenance Billboard']);

        // Filter by screen_type=LED Video Wall
        $response2 = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens?screen_type=LED Video Wall');
        $response2->assertStatus(200);
        $response2->assertJsonPath('meta.total', 1);
        $response2->assertJsonFragment(['name' => 'Active LED']);
    }

    public function test_provider_can_view_own_screen_details()
    {
        $screen = Screen::create([
            'name' => 'My Private Screen',
            'screen_code' => 'SCR-PRIV',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Lulu Mall',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        $response = $this->actingAs($this->providerUser)
            ->getJson("/api/screen-provider/screens/{$screen->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.screen.name', 'My Private Screen');
    }

    public function test_provider_cannot_view_other_provider_screen()
    {
        $otherScreen = Screen::create([
            'name' => 'Other Provider Screen',
            'screen_code' => 'SCR-OTH',
            'screen_type' => 'Outdoor Billboard',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->otherProvider->id,
        ]);

        $response = $this->actingAs($this->providerUser)
            ->getJson("/api/screen-provider/screens/{$otherScreen->id}");

        $response->assertStatus(403);
    }

    public function test_provider_can_update_own_screen_permitted_fields()
    {
        $screen = Screen::create([
            'name' => 'Original Screen Name',
            'screen_code' => 'SCR-UPD',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Lulu Mall',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        $payload = [
            'name' => 'Updated Screen Name',
            'screen_type' => 'Standee Display',
            'location_name' => 'New Lobby Area',
            'status' => 'maintenance',
            'resolution' => '3840x2160',
            'orientation' => 'portrait',
            'operating_hours' => '10 AM - 9 PM',
            'latitude' => 10.0269,
            'longitude' => 76.3079,
        ];

        $response = $this->actingAs($this->providerUser)
            ->putJson("/api/screen-provider/screens/{$screen->id}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('screens', [
            'id' => $screen->id,
            'name' => 'Updated Screen Name',
            'screen_type' => 'Standee Display',
            'location_name' => 'New Lobby Area',
            'status' => 'maintenance',
            'resolution' => '3840x2160',
            'orientation' => 'portrait',
            'operating_hours' => '10 AM - 9 PM',
            'latitude' => 10.0269000,
            'longitude' => 76.3079000,
        ]);
    }

    public function test_provider_cannot_update_restricted_fields()
    {
        $screen = Screen::create([
            'name' => 'Security Screen',
            'screen_code' => 'SCR-SEC',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Lulu Mall',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        $newPlan = PricingPlan::create([
            'name' => 'Expensive Plan',
            'base_price' => 10000,
            'currency' => 'INR',
            'is_active' => true
        ]);

        // Attempting to change ownership, pricing_plan, and unique screen code
        $payload = [
            'pricing_plan_id' => $newPlan->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => $this->otherProvider->id,
            'screen_code' => 'SCR-FORGED-CODE',
        ];

        $response = $this->actingAs($this->providerUser)
            ->putJson("/api/screen-provider/screens/{$screen->id}", $payload);

        $response->assertStatus(200);
        
        // Assert that the database remains unchanged for restricted fields
        $screen->refresh();
        $this->assertEquals($this->pricingPlan->id, $screen->pricing_plan_id);
        $this->assertEquals(Screen::OWNER_TYPE_PROVIDER, $screen->owner_type);
        $this->assertEquals($this->provider->id, $screen->screen_provider_id);
        $this->assertEquals('SCR-SEC', $screen->screen_code);
    }

    public function test_provider_cannot_update_other_provider_screen()
    {
        $otherScreen = Screen::create([
            'name' => 'Other Screen',
            'screen_code' => 'SCR-OTH-UPD',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->otherProvider->id,
        ]);

        $payload = [
            'name' => 'Hacked Screen Name',
        ];

        $response = $this->actingAs($this->providerUser)
            ->putJson("/api/screen-provider/screens/{$otherScreen->id}", $payload);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('screens', [
            'id' => $otherScreen->id,
            'name' => 'Hacked Screen Name',
        ]);
    }

    public function test_provider_can_create_screen()
    {
        $payload = [
            'name' => 'Provider Created Screen',
            'screen_code' => 'SCR-NEW-CRE',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Kochi Airport',
            'status' => 'active',
            'orientation' => 'landscape',
            'resolution' => '1920x1080',
            'operating_hours' => '24 Hours',
            'latitude' => 10.152,
            'longitude' => 76.391,
        ];

        $response = $this->actingAs($this->providerUser)
            ->postJson('/api/screen-provider/screens', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Provider Created Screen');

        $this->assertDatabaseHas('screens', [
            'screen_code' => 'SCR-NEW-CRE',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => null,
        ]);
    }

    public function test_provider_creation_forces_ownership_and_ignores_injected_pricing_plan()
    {
        $payload = [
            'name' => 'Spoofed Owner Screen',
            'screen_code' => 'SCR-SPOOF',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Main Street',
            'status' => 'active',
            // Attempting to spoof owner type and provider
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => $this->otherProvider->id,
            'pricing_plan_id' => $this->pricingPlan->id,
        ];

        $response = $this->actingAs($this->providerUser)
            ->postJson('/api/screen-provider/screens', $payload);

        $response->assertStatus(201);

        // Assert backend override took precedence and secured the data
        $this->assertDatabaseHas('screens', [
            'screen_code' => 'SCR-SPOOF',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id, // locked to logged-in provider
            'pricing_plan_id' => null, // locked to null
        ]);
    }

    public function test_provider_cannot_mutate_screen_wise_split_percentages()
    {
        $screen = Screen::create([
            'name' => 'Screen Split Guarded',
            'screen_code' => 'SCR-SPLIT-G',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'City Square',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // Malicious attempt to change split to 5% Illuvision / 95% Provider
        $payload = [
            'name' => 'Updated Name',
            'illuvision_percentage' => 5.00,
            'screen_provider_percentage' => 95.00,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
        ];

        $response = $this->actingAs($this->providerUser)
            ->putJson("/api/screen-provider/screens/{$screen->id}", $payload);

        $response->assertStatus(200);

        // Verify split remained unchanged
        $screen->refresh();
        $this->assertEquals(20.00, (float) $screen->illuvision_percentage);
        $this->assertEquals(80.00, (float) $screen->screen_provider_percentage);
        $this->assertEquals(Screen::OWNER_TYPE_PROVIDER, $screen->owner_type);
    }

    public function test_screen_details_returns_eager_loaded_commercial_and_provider_data()
    {
        $screen = Screen::create([
            'name' => 'Commercial Display Screen',
            'screen_code' => 'SCR-COMM-VIEW',
            'screen_type' => 'LED Video Wall',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Grand Central',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);

        $response = $this->actingAs($this->providerUser)
            ->getJson("/api/screen-provider/screens/{$screen->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.screen.pricing_plan.name', 'Standard Plan');
        $response->assertJsonPath('data.screen.screen_provider.provider_name', 'Provider A Corp');
        $response->assertJsonPath('data.screen.illuvision_percentage', 15);
        $response->assertJsonPath('data.screen.screen_provider_percentage', 85);
        $response->assertJsonPath('data.screen.owner_type', Screen::OWNER_TYPE_PROVIDER);
    }

    public function test_mobile_asset_commercial_and_split_consistency()
    {
        $mobileScreen = Screen::create([
            'name' => 'Mobile Fleet Transit LED',
            'screen_code' => 'MOB-FLT-1',
            'screen_type' => 'Mobile Van Display',
            'asset_type' => 'mobile',
            'fleet_size' => 5,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Metro Transit Route',
            'pricing_plan_id' => $this->pricingPlan->id,
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
        ]);

        $response = $this->actingAs($this->providerUser)
            ->getJson("/api/screen-provider/screens/{$mobileScreen->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.screen.name', 'Mobile Fleet Transit LED');
        $response->assertJsonPath('data.screen.fleet_size', 5);
        $response->assertJsonPath('data.screen.illuvision_percentage', 25);
        $response->assertJsonPath('data.screen.screen_provider_percentage', 75);
    }

    public function test_provider_can_create_mobile_asset_with_coverage_sync()
    {
        $state1 = State::create(['name' => 'Kerala', 'country_id' => $this->country->id, 'is_active' => true]);
        $state2 = State::create(['name' => 'Tamil Nadu', 'country_id' => $this->country->id, 'is_active' => true]);
        $city2 = City::create(['name' => 'Trivandrum', 'country_id' => $this->country->id, 'state_id' => $state1->id, 'is_active' => true]);
        $city3 = City::create(['name' => 'Chennai', 'country_id' => $this->country->id, 'state_id' => $state2->id, 'is_active' => true]);

        $payload = [
            'name' => 'Kochi-Chennai Express LED Fleet',
            'screen_code' => 'MOB-EXP-01',
            'screen_type' => 'Transit LED',
            'asset_type' => 'mobile',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Depot Central Hub',
            'status' => 'active',
            'fleet_size' => 15,
            'working_hours_pd' => 14,
            'travel_distance_km_pd' => 220,
            'coverage_state_ids' => [$state1->id, $state2->id],
            'coverage_city_ids' => [$this->city->id, $city2->id, $city3->id],
        ];

        $response = $this->actingAs($this->providerUser)
            ->postJson('/api/screen-provider/screens', $payload);

        $response->assertStatus(201);
        $screenId = $response->json('data.id');

        $this->assertDatabaseHas('screens', [
            'id' => $screenId,
            'asset_type' => 'mobile',
            'fleet_size' => 15,
            'working_hours_pd' => 14,
            'travel_distance_km_pd' => 220,
            'screen_provider_id' => $this->provider->id,
        ]);

        $createdScreen = Screen::with(['coverageStates', 'coverageCities'])->find($screenId);
        $this->assertCount(2, $createdScreen->coverageStates);
        $this->assertCount(3, $createdScreen->coverageCities);
    }

    public function test_provider_can_update_mobile_asset_and_coverage()
    {
        $state1 = State::create(['name' => 'Karnataka', 'country_id' => $this->country->id, 'is_active' => true]);
        $city2 = City::create(['name' => 'Bangalore', 'country_id' => $this->country->id, 'state_id' => $state1->id, 'is_active' => true]);

        $screen = Screen::create([
            'name' => 'Original Transit Fleet',
            'screen_code' => 'MOB-INIT-01',
            'screen_type' => 'Transit LED',
            'asset_type' => 'mobile',
            'fleet_size' => 5,
            'working_hours_pd' => 10,
            'travel_distance_km_pd' => 100,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Depot 1',
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        $payload = [
            'name' => 'Expanded Transit Fleet',
            'screen_type' => 'Transit LED HD',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Depot 1 Main Hub',
            'fleet_size' => 12,
            'working_hours_pd' => 16,
            'travel_distance_km_pd' => 250,
            'coverage_state_ids' => [$state1->id],
            'coverage_city_ids' => [$city2->id],
        ];

        $response = $this->actingAs($this->providerUser)
            ->putJson("/api/screen-provider/screens/{$screen->id}", $payload);

        $response->assertStatus(200);

        $screen->refresh();
        $this->assertEquals('Expanded Transit Fleet', $screen->name);
        $this->assertEquals(12, $screen->fleet_size);
        $this->assertEquals(16, $screen->working_hours_pd);
        $this->assertEquals(250, $screen->travel_distance_km_pd);
        $this->assertCount(1, $screen->coverageStates);
        $this->assertCount(1, $screen->coverageCities);
    }

    public function test_provider_can_filter_screens_by_asset_type_and_stats_are_returned()
    {
        // 2 fixed screens
        Screen::create([
            'name' => 'Fixed Screen 1',
            'screen_code' => 'SCR-FIX-1',
            'screen_type' => 'Fixed LED',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Mall A',
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        Screen::create([
            'name' => 'Fixed Screen 2',
            'screen_code' => 'SCR-FIX-2',
            'screen_type' => 'Fixed Billboard',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Mall B',
            'status' => 'maintenance',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        // 1 mobile fleet
        Screen::create([
            'name' => 'Mobile Fleet 1',
            'screen_code' => 'MOB-FLT-01',
            'screen_type' => 'Mobile Van',
            'asset_type' => 'mobile',
            'fleet_size' => 8,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Logistics Park',
            'status' => 'active',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
        ]);

        // Request all screens - verify stats
        $allResponse = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens');

        $allResponse->assertStatus(200);
        $allResponse->assertJsonPath('meta.total', 3);
        $allResponse->assertJsonPath('meta.stats.total_screens', 3);
        $allResponse->assertJsonPath('meta.stats.active_screens', 2);
        $allResponse->assertJsonPath('meta.stats.fixed_screens', 2);
        $allResponse->assertJsonPath('meta.stats.mobile_screens', 1);

        // Request only mobile screens filter
        $mobileFilterResponse = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens?asset_type=mobile');

        $mobileFilterResponse->assertStatus(200);
        $mobileFilterResponse->assertJsonPath('meta.total', 1);
        $mobileFilterResponse->assertJsonPath('data.0.name', 'Mobile Fleet 1');

        // Request only fixed screens filter
        $fixedFilterResponse = $this->actingAs($this->providerUser)
            ->getJson('/api/screen-provider/screens?asset_type=fixed');

        $fixedFilterResponse->assertStatus(200);
        $fixedFilterResponse->assertJsonPath('meta.total', 2);
    }
}

