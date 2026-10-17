<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\ScreenCategory;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AssetManagementFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Country $india;
    protected Country $uae;
    protected State $kerala;
    protected State $tamilNadu;
    protected State $dubaiState;
    protected City $kochi;
    protected City $trivandrum;
    protected City $chennai;
    protected City $dubaiCity;
    protected ScreenCategory $mallCategory;
    protected ScreenCategory $transitCategory;
    protected PricingPlan $growthPlan;
    protected PricingPlan $scalePlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'superadmin',
            'status' => 'active',
        ]);

        // Setup Geographic Hierarchy
        $this->india = Country::create(['name' => 'India', 'code' => 'IN', 'phone_code' => '+91', 'is_active' => true]);
        $this->uae = Country::create(['name' => 'United Arab Emirates', 'code' => 'AE', 'phone_code' => '+971', 'is_active' => true]);

        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->india->id, 'is_active' => true]);
        $this->tamilNadu = State::create(['name' => 'Tamil Nadu', 'country_id' => $this->india->id, 'is_active' => true]);
        $this->dubaiState = State::create(['name' => 'Dubai', 'country_id' => $this->uae->id, 'is_active' => true]);

        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->india->id, 'is_active' => true]);
        $this->trivandrum = City::create(['name' => 'Trivandrum', 'state_id' => $this->kerala->id, 'country_id' => $this->india->id, 'is_active' => true]);
        $this->chennai = City::create(['name' => 'Chennai', 'state_id' => $this->tamilNadu->id, 'country_id' => $this->india->id, 'is_active' => true]);
        $this->dubaiCity = City::create(['name' => 'Dubai City', 'state_id' => $this->dubaiState->id, 'country_id' => $this->uae->id, 'is_active' => true]);

        // Setup Categories & Pricing Plans
        $this->mallCategory = ScreenCategory::create(['name' => 'Mall Displays', 'status' => 'active']);
        $this->transitCategory = ScreenCategory::create(['name' => 'Transit Hubs', 'status' => 'active']);

        $this->growthPlan = PricingPlan::create([
            'name' => 'Business Growth',
            'base_price' => 500,
            'minimum_duration_type' => 'day',
            'minimum_duration_value' => 1,
            'currency' => 'INR',
            'status' => 'active',
        ]);

        $this->scalePlan = PricingPlan::create([
            'name' => 'Scale Tier',
            'base_price' => 1500,
            'minimum_duration_type' => 'day',
            'minimum_duration_value' => 1,
            'currency' => 'INR',
            'status' => 'active',
        ]);

        // Create Seed Assets
        // 1. Kochi - Fixed - Mall - Business Growth
        Screen::create([
            'name' => 'Lulu Mall LED 1',
            'screen_code' => 'AST-KOC-01',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Video Wall',
            'location_name' => 'Lulu Mall Food Court',
            'country_id' => $this->india->id,
            'city_id' => $this->kochi->id,
            'screen_category_id' => $this->mallCategory->id,
            'pricing_plan_id' => $this->growthPlan->id,
            'status' => 'active',
            'owner_type' => 'direct_illuvision',
            'fleet_size' => 1,
        ]);

        // 2. Kochi - Fixed - Transit - Scale Tier
        Screen::create([
            'name' => 'Kochi Metro Billboard',
            'screen_code' => 'AST-KOC-02',
            'asset_type' => 'fixed',
            'screen_type' => 'Outdoor Billboard',
            'location_name' => 'MG Road Station',
            'country_id' => $this->india->id,
            'city_id' => $this->kochi->id,
            'screen_category_id' => $this->transitCategory->id,
            'pricing_plan_id' => $this->scalePlan->id,
            'status' => 'active',
            'owner_type' => 'direct_illuvision',
            'fleet_size' => 1,
        ]);

        // 3. Trivandrum - Fixed - Mall - Business Growth
        Screen::create([
            'name' => 'Mall of Travancore Screen',
            'screen_code' => 'AST-TRV-01',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Video Wall',
            'location_name' => 'MOT Atrium',
            'country_id' => $this->india->id,
            'city_id' => $this->trivandrum->id,
            'screen_category_id' => $this->mallCategory->id,
            'pricing_plan_id' => $this->growthPlan->id,
            'status' => 'active',
            'owner_type' => 'direct_illuvision',
            'fleet_size' => 1,
        ]);

        // 4. Chennai - Fixed - Transit - Business Growth
        Screen::create([
            'name' => 'Chennai Central Wall',
            'screen_code' => 'AST-CHE-01',
            'asset_type' => 'fixed',
            'screen_type' => 'Wall Mounted Display',
            'location_name' => 'Central Platform 1',
            'country_id' => $this->india->id,
            'city_id' => $this->chennai->id,
            'screen_category_id' => $this->transitCategory->id,
            'pricing_plan_id' => $this->growthPlan->id,
            'status' => 'active',
            'owner_type' => 'direct_illuvision',
            'fleet_size' => 1,
        ]);

        // 5. Dubai - Fixed - Mall - Scale Tier
        Screen::create([
            'name' => 'Dubai Mall Screen',
            'screen_code' => 'AST-DXB-01',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Video Wall',
            'location_name' => 'Fashion Avenue',
            'country_id' => $this->uae->id,
            'city_id' => $this->dubaiCity->id,
            'screen_category_id' => $this->mallCategory->id,
            'pricing_plan_id' => $this->scalePlan->id,
            'status' => 'active',
            'owner_type' => 'direct_illuvision',
            'fleet_size' => 1,
        ]);
    }

    /** @test */
    public function it_filters_assets_by_country_only()
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/screens?country_id={$this->india->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(4, $data);
        foreach ($data as $item) {
            $this->assertEquals($this->india->id, $item['country_id']);
        }
    }

    /** @test */
    public function it_filters_assets_by_country_and_state()
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/screens?country_id={$this->india->id}&state_id={$this->kerala->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Kerala has Lulu Mall LED 1, Kochi Metro Billboard, Mall of Travancore Screen
        $this->assertCount(3, $data);
        foreach ($data as $item) {
            $this->assertContains($item['city_id'], [$this->kochi->id, $this->trivandrum->id]);
        }
    }

    /** @test */
    public function it_filters_assets_by_country_state_and_city()
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/screens?country_id={$this->india->id}&state_id={$this->kerala->id}&city_id={$this->kochi->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Kochi has 2 screens
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertEquals($this->kochi->id, $item['city_id']);
        }
    }

    /** @test */
    public function it_filters_by_location_category_and_pricing_plan_combined()
    {
        // India -> Kerala -> Kochi -> Mall Displays -> Business Growth
        $url = "/api/screens?country_id={$this->india->id}&state_id={$this->kerala->id}&city_id={$this->kochi->id}&screen_category_id={$this->mallCategory->id}&pricing_plan_id={$this->growthPlan->id}";
        $response = $this->actingAs($this->admin)->getJson($url);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('Lulu Mall LED 1', $data[0]['name']);
        $this->assertEquals($this->growthPlan->id, $data[0]['pricing_plan_id']);
    }

    /** @test */
    public function it_returns_accurate_stats_matching_active_filters()
    {
        // Filter by Kerala (3 screens, 3 fixed, 0 mobile)
        $response = $this->actingAs($this->admin)
            ->getJson("/api/screens?country_id={$this->india->id}&state_id={$this->kerala->id}");

        $response->assertStatus(200);
        $stats = $response->json('stats') ?? $response->json('meta.stats');

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(3, $stats['fixed_count']);
        $this->assertEquals(0, $stats['mobile_count']);
        $this->assertEquals(3, $stats['total_units']);
    }

    /** @test */
    public function it_returns_empty_data_for_nonexistent_filter_combinations()
    {
        // UAE -> Kochi (invalid combo, Kochi is in India)
        $response = $this->actingAs($this->admin)
            ->getJson("/api/screens?country_id={$this->uae->id}&city_id={$this->kochi->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(0, $data);
    }
}
