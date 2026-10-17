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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenDurationSplitOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected ScreenProvider $providerA;
    protected ScreenProvider $providerB;
    protected PricingPlan $planA;
    protected PricingPlan $planB;
    protected PricingDurationRule $ruleA30;
    protected PricingDurationRule $ruleA60;
    protected PricingDurationRule $ruleB30;
    protected Screen $screenA;
    protected Screen $screenB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $country = Country::create(['name' => 'India', 'code' => 'IN']);
        $city = City::create(['name' => 'Kochi', 'country_id' => $country->id]);
        $category = ScreenCategory::create(['name' => 'Digital Billboard', 'slug' => 'digital-billboard']);

        // 1. Providers
        $this->providerA = ScreenProvider::create([
            'provider_name' => 'Provider Alpha',
            'business_name' => 'Alpha Media Ltd',
            'contact_person' => 'John Doe',
            'phone' => '+919876543210',
            'email' => 'alpha@example.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
        ]);

        $this->providerB = ScreenProvider::create([
            'provider_name' => 'Provider Beta',
            'business_name' => 'Beta Networks Ltd',
            'contact_person' => 'Jane Smith',
            'phone' => '+919876543211',
            'email' => 'beta@example.com',
            'business_type' => 'company',
            'status' => ScreenProvider::STATUS_APPROVED,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
        ]);

        // 2. Pricing Plans
        $this->planA = PricingPlan::create([
            'name' => 'Plan Alpha',
            'base_price' => 100.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
            'is_active' => true,
        ]);

        $this->planB = PricingPlan::create([
            'name' => 'Plan Beta',
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
        $this->screenA = Screen::create([
            'name' => 'MG Road Billboard',
            'screen_code' => 'SCR-A-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'MG Road Junction',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => $this->planA->id,
            'screen_category_id' => $category->id,
            'country_id' => $country->id,
            'city_id' => $city->id,
            'illuvision_percentage' => 30.00,
            'screen_provider_percentage' => 70.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->screenB = Screen::create([
            'name' => 'Airport Display',
            'screen_code' => 'SCR-B-001',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Airport Terminal 1',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerB->id,
            'pricing_plan_id' => $this->planB->id,
            'screen_category_id' => $category->id,
            'country_id' => $country->id,
            'city_id' => $city->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    /** @test */
    public function it_creates_duration_split_override_and_resolves_all_bidirectional_relationships()
    {
        $override = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // 1. Verify direct model properties
        $this->assertDatabaseHas('screen_duration_split_overrides', [
            'id' => $override->id,
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // 2. Override -> Screen
        $this->assertEquals($this->screenA->id, $override->screen->id);
        $this->assertEquals('MG Road Billboard', $override->screen->name);

        // 3. Override -> PricingPlan
        $this->assertEquals($this->planA->id, $override->pricingPlan->id);
        $this->assertEquals('Plan Alpha', $override->pricingPlan->name);

        // 4. Override -> PricingDurationRule
        $this->assertEquals($this->ruleA30->id, $override->pricingDurationRule->id);
        $this->assertEquals(30.00, (float) $override->pricingDurationRule->minimum_duration);

        // 5. Screen -> durationSplitOverrides
        $this->assertCount(1, $this->screenA->durationSplitOverrides);
        $this->assertEquals($override->id, $this->screenA->durationSplitOverrides->first()->id);

        // 6. PricingDurationRule -> splitOverrides
        $this->assertCount(1, $this->ruleA30->splitOverrides);
        $this->assertEquals($override->id, $this->ruleA30->splitOverrides->first()->id);

        // 7. PricingPlan -> durationSplitOverrides
        $this->assertCount(1, $this->planA->durationSplitOverrides);
        $this->assertEquals($override->id, $this->planA->durationSplitOverrides->first()->id);
    }

    /** @test */
    public function it_maintains_strict_isolation_between_screens_and_plans()
    {
        $overrideA = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 40.00,
            'screen_provider_percentage' => 60.00,
            'is_active' => true,
        ]);

        $overrideB = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenB->id,
            'pricing_plan_id' => $this->planB->id,
            'pricing_duration_rule_id' => $this->ruleB30->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'is_active' => true,
        ]);

        // Screen A only sees Override A
        $screenAOverrides = $this->screenA->fresh()->durationSplitOverrides;
        $this->assertCount(1, $screenAOverrides);
        $this->assertEquals(40.00, (float) $screenAOverrides->first()->illuvision_percentage);

        // Screen B only sees Override B
        $screenBOverrides = $this->screenB->fresh()->durationSplitOverrides;
        $this->assertCount(1, $screenBOverrides);
        $this->assertEquals(20.00, (float) $screenBOverrides->first()->illuvision_percentage);
    }

    /** @test */
    public function it_prevents_duplicate_configuration_via_database_unique_constraint()
    {
        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        // Attempt to insert a second duplicate record for same Screen + Plan + DurationRule
        $this->expectException(QueryException::class);

        ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 50.00,
            'screen_provider_percentage' => 50.00,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_allows_multiple_distinct_duration_rules_for_the_same_screen_and_plan()
    {
        $override30 = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        $override60 = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA60->id,
            'illuvision_percentage' => 25.00,
            'screen_provider_percentage' => 75.00,
            'is_active' => true,
        ]);

        $overrides = $this->screenA->fresh()->durationSplitOverrides;
        $this->assertCount(2, $overrides);

        $this->assertEquals(35.00, (float) $overrides->where('pricing_duration_rule_id', $this->ruleA30->id)->first()->illuvision_percentage);
        $this->assertEquals(25.00, (float) $overrides->where('pricing_duration_rule_id', $this->ruleA60->id)->first()->illuvision_percentage);
    }

    /** @test */
    public function it_toggles_active_state_correctly()
    {
        $override = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        $this->assertTrue($override->fresh()->is_active);

        $override->update(['is_active' => false]);
        $this->assertFalse($override->fresh()->is_active);
    }

    /** @test */
    public function it_preserves_existing_screen_and_provider_split_values_intact()
    {
        // Assert existing Screen Split and Provider Split values are unmodified
        $this->assertEquals(30.00, (float) $this->screenA->illuvision_percentage);
        $this->assertEquals(70.00, (float) $this->screenA->screen_provider_percentage);
        $this->assertEquals(40.00, (float) $this->providerA->illuvision_percentage);
        $this->assertEquals(60.00, (float) $this->providerA->screen_provider_percentage);

        // Verify new table starts completely empty for screens without overrides
        $this->assertCount(0, $this->screenA->durationSplitOverrides);
        $this->assertCount(0, $this->screenB->durationSplitOverrides);
    }

    /** @test */
    public function it_cascades_deletion_when_parent_screen_is_deleted()
    {
        $override = ScreenDurationSplitOverride::create([
            'screen_id' => $this->screenA->id,
            'pricing_plan_id' => $this->planA->id,
            'pricing_duration_rule_id' => $this->ruleA30->id,
            'illuvision_percentage' => 35.00,
            'screen_provider_percentage' => 65.00,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('screen_duration_split_overrides', ['id' => $override->id]);

        // Force delete screen (to trigger database foreign key cascade)
        $this->screenA->forceDelete();

        $this->assertDatabaseMissing('screen_duration_split_overrides', ['id' => $override->id]);
    }
}
