<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ScreenProvider;
use App\Models\User;
use App\Services\RevenueSplitResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScreenManagementDurationSplitTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected ScreenProvider $providerA;
    protected ScreenProvider $providerB;
    protected PricingPlan $planA;
    protected PricingPlan $planB;
    protected PricingDurationRule $ruleA30;
    protected PricingDurationRule $ruleA60;
    protected PricingDurationRule $ruleA90;
    protected PricingDurationRule $ruleAInactive;
    protected PricingDurationRule $ruleB30;
    protected ScreenCategory $category;
    protected Country $country;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Admin user with superadmin role
        $this->adminUser = User::factory()->create(['role' => 'superadmin', 'status' => 'active']);
        Sanctum::actingAs($this->adminUser);

        // 2. Geography & Category
        $this->country = Country::create(['name' => 'India', 'code' => 'IN']);
        $this->city = City::create(['name' => 'Kochi', 'country_id' => $this->country->id]);
        $this->category = ScreenCategory::create(['name' => 'Digital Billboard', 'slug' => 'digital-billboard']);

        // 3. Providers
        $this->providerA = ScreenProvider::create([
            'provider_name' => 'Provider Alpha',
            'business_name' => 'Alpha Media Ltd',
            'contact_person' => 'Alice',
            'phone' => '+919876543210',
            'email' => 'alpha@media.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
        ]);

        $this->providerB = ScreenProvider::create([
            'provider_name' => 'Provider Beta',
            'business_name' => 'Beta Media Ltd',
            'contact_person' => 'Bob',
            'phone' => '+919876543211',
            'email' => 'beta@media.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
        ]);

        // 4. Pricing Plans
        $this->planA = PricingPlan::create([
            'name' => 'Business Growth Plan',
            'base_price' => 150.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        $this->planB = PricingPlan::create([
            'name' => 'Enterprise Prime Plan',
            'base_price' => 300.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        // 5. Duration Rules
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

        $this->ruleAInactive = PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 120.00,
            'maximum_duration' => 179.00,
            'duration_unit' => 'minute',
            'discount_type' => 'Percentage',
            'discount_value' => 15.00,
            'sort_order' => 4,
            'status' => 'inactive',
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
    }

    /** @test */
    public function it_creates_a_screen_with_valid_duration_split_overrides()
    {
        $payload = [
            'name' => 'MG Road Digital Billboard',
            'screen_code' => 'SCR-MGR-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'MG Road Junction',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleA30->id,
                    'illuvision_percentage' => 40.00,
                    'screen_provider_percentage' => 60.00,
                    'is_active' => true,
                ],
                [
                    'pricing_duration_rule_id' => $this->ruleA60->id,
                    'illuvision_percentage' => 35.00,
                    'screen_provider_percentage' => 65.00,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/screens', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'MG Road Digital Billboard')
            ->assertJsonPath('data.pricing_plan_id', $this->planA->id)
            ->assertJsonCount(2, 'data.duration_split_overrides');

        $screenId = $response->json('data.id');

        $this->assertDatabaseHas('screen_duration_split_overrides', [
            'screen_id' => $screenId,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('screen_duration_split_overrides', [
            'screen_id' => $screenId,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => 1,
        ]);
    }

    /** @test */
    public function it_rejects_duration_overrides_when_percentages_do_not_sum_to_100()
    {
        $payload = [
            'name' => 'Invalid Split Screen',
            'screen_code' => 'SCR-INV-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Invalid Junction',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'status' => 'active',
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleA30->id,
                    'illuvision_percentage' => 70.00,
                    'screen_provider_percentage' => 40.00, // Sum = 110%
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/screens', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseMissing('screens', ['screen_code' => 'SCR-INV-001']);
    }

    /** @test */
    public function it_rejects_duplicate_duration_rules_in_the_same_request()
    {
        $payload = [
            'name' => 'Duplicate Rule Screen',
            'screen_code' => 'SCR-DUP-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Duplicate Junction',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'status' => 'active',
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleA30->id,
                    'illuvision_percentage' => 40.00,
                    'screen_provider_percentage' => 60.00,
                    'is_active' => true,
                ],
                [
                    'pricing_duration_rule_id' => $this->ruleA30->id, // Duplicate rule ID
                    'illuvision_percentage' => 35.00,
                    'screen_provider_percentage' => 65.00,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/screens', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseMissing('screens', ['screen_code' => 'SCR-DUP-001']);
    }

    /** @test */
    public function it_rejects_duration_rule_belonging_to_another_pricing_plan()
    {
        $payload = [
            'name' => 'Wrong Plan Rule Screen',
            'screen_code' => 'SCR-WRP-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Wrong Plan Junction',
            'pricing_plan_id' => $this->planA->id, // Plan A
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'status' => 'active',
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleB30->id, // Belongs to Plan B!
                    'illuvision_percentage' => 40.00,
                    'screen_provider_percentage' => 60.00,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/screens', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseMissing('screens', ['screen_code' => 'SCR-WRP-001']);
    }

    /** @test */
    public function it_rejects_active_override_for_inactive_duration_rule()
    {
        $payload = [
            'name' => 'Inactive Rule Screen',
            'screen_code' => 'SCR-INACT-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Inactive Rule Junction',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'status' => 'active',
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleAInactive->id, // Inactive rule
                    'illuvision_percentage' => 40.00,
                    'screen_provider_percentage' => 60.00,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->postJson('/api/screens', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $this->assertDatabaseMissing('screens', ['screen_code' => 'SCR-INACT-001']);
    }

    /** @test */
    public function it_updates_existing_duration_overrides_and_disables_or_deletes_omitted_ones()
    {
        // 1. Create Screen with 30m and 60m overrides
        $screen = Screen::create([
            'name' => 'Screen To Update',
            'screen_code' => 'SCR-UPD-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Update Site',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // 2. Update: modify 30m to 25/75, add 90m @ 20/80, omit 60m
        $updatePayload = [
            'duration_split_overrides' => [
                [
                    'pricing_duration_rule_id' => $this->ruleA30->id,
                    'illuvision_percentage' => 25.00,
                    'screen_provider_percentage' => 75.00,
                    'is_active' => true,
                ],
                [
                    'pricing_duration_rule_id' => $this->ruleA90->id,
                    'illuvision_percentage' => 20.00,
                    'screen_provider_percentage' => 80.00,
                    'is_active' => true,
                ],
            ],
        ];

        $response = $this->putJson("/api/screens/{$screen->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        // 30m updated
        $this->assertDatabaseHas('screen_duration_split_overrides', [
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
        ]);

        // 90m created
        $this->assertDatabaseHas('screen_duration_split_overrides', [
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA90->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // 60m removed
        $this->assertDatabaseMissing('screen_duration_split_overrides', [
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
        ]);
    }

    /** @test */
    public function show_endpoint_returns_populated_duration_split_overrides()
    {
        $screen = Screen::create([
            'name' => 'Screen For Show',
            'screen_code' => 'SCR-SHOW-001',
            'screen_type' => 'digital_billboard',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Show Site',
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'status' => 'active',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 45.00,
            'screen_provider_percentage' => 55.00,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/screens/{$screen->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.duration_split_overrides.0.pricing_duration_rule_id', $this->ruleA30->id);
        $this->assertEquals(45.00, $response->json('data.duration_split_overrides.0.illuvision_percentage'));
        $this->assertEquals(55.00, $response->json('data.duration_split_overrides.0.screen_provider_percentage'));
    }

    /** @test */
    public function duration_override_operates_independently_of_screen_static_split_and_integrates_with_resolver()
    {
        // 1. Create Screen with duration override
        $screen = Screen::create([
            'name' => 'Independent Split Screen',
            'screen_code' => 'SCR-IND-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Ind Site',
            'screen_category_id' => $this->category->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => 'screen_provider',
            'screen_provider_id' => $this->providerA->id,
            'illuvision_percentage' => 10.00,
            'screen_provider_percentage' => 90.00,
            'status' => 'active',
        ]);

        ScreenDurationSplitOverride::create([
            'screen_id' => $screen->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        $resolver = app(RevenueSplitResolverService::class);

        // 60-min rule -> Uses Duration Override (35/65) even when screen has static split
        $res60 = $resolver->resolveEffectiveSplit($screen, $this->planA, $this->ruleA60);
        $this->assertEquals(35.00, $res60['illuvision_percentage']);
        $this->assertEquals(65.00, $res60['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_DURATION_OVERRIDE, $res60['resolution_source']);

        // 30-min rule (no override configured) -> Falls through to Screen Split (10/90)
        $res30 = $resolver->resolveEffectiveSplit($screen, $this->planA, $this->ruleA30);
        $this->assertEquals(10.00, $res30['illuvision_percentage']);
        $this->assertEquals(90.00, $res30['screen_provider_percentage']);
        $this->assertEquals(RevenueSplitResolverService::SOURCE_SCREEN_OVERRIDE, $res30['resolution_source']);
    }
}
