<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Models\PricingScreenRule;
use App\Models\PricingDurationRule;
use App\Models\PricingPeakRule;
use App\Models\PricingWeekendRule;
use App\Services\PricingRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    protected PricingRuleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(PricingRuleEngine::class);
    }

    public function test_pricing_rule_engine_calculates_base_price_and_compound_discounts()
    {
        // 1. Create a pricing plan
        $plan = PricingPlan::create([
            'name' => 'Premium Signage Plan',
            'description' => 'For testing compound rules',
            'base_price' => 4999.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Month',
            'is_default' => true,
            'is_active' => true,
        ]);

        // 2. Add Screen Count Discount Rule: 5 screens -> 20%
        PricingScreenRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_screens' => 5,
            'maximum_screens' => 9,
            'discount_type' => 'Percentage',
            'discount_value' => 20.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        // 3. Add Duration Discount Rule: 6 Months -> 20%
        PricingDurationRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_duration' => 6.00,
            'maximum_duration' => 12.00,
            'duration_unit' => 'Month',
            'discount_type' => 'Percentage',
            'discount_value' => 20.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        // 4. Calculate for 5 screens and 6 months campaign
        // Base Price = 4999 * 5 screens * 6 months = 149970
        // Combined Discount = 1 - (1 - 0.20) * (1 - 0.20) = 36%
        // Final Price = 149970 * 0.64 = 95980.80
        $result = $this->engine->calculate($plan, [
            'screens' => 5,
            'start_date' => '2026-06-01',
            'end_date' => '2026-11-30', // exactly 6 months (183 days)
            'daily_start_time' => '10:00',
            'daily_end_time' => '12:00',
        ]);

        $this->assertEquals(149970.00, $result['base_price']);
        $this->assertEquals(36.00, $result['combined_discount']);
        $this->assertEquals(53989.20, $result['discount_amount']); // 149970 * 0.36 = 53989.20
        $this->assertEquals(95980.80, $result['final_price']);
    }

    public function test_pricing_rule_engine_applies_peak_hour_markup()
    {
        $plan = PricingPlan::create([
            'name' => 'Premium Signage Plan',
            'base_price' => 1000.00,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
        ]);

        // Peak rule: 18:00 to 20:00, Friday/Saturday, 1.5x multiplier
        PricingPeakRule::create([
            'pricing_plan_id' => $plan->id,
            'day_of_week' => 'Fri,Sat',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'pricing_type' => 'multiplier',
            'value' => 1.50,
            'status' => 'active',
        ]);

        // Calculate for 1 screen, 4 hours on Saturday 17:00 to 21:00
        // Peak overlap is 18:00 to 20:00 = 2 hours.
        // Base price = 1000 * 4 hours = 4000
        // Hourly rate = 1000
        // Peak surcharge = 1000 * 2 hours * (1.5 - 1.0) = 1000
        // Final price = 4000 + 1000 = 5000
        $result = $this->engine->calculate($plan, [
            'screens' => 1,
            'duration_hours' => 4.0,
            'duration_unit' => 'Hour',
            'day' => 'Sat',
            'time' => '17:00',
        ]);

        $this->assertEquals(4000.00, $result['base_price']);
        $this->assertEquals(1000.00, $result['peak_amount']);
        $this->assertEquals(5000.00, $result['final_price']);
    }

    public function test_pricing_rule_engine_applies_weekend_flat_markup()
    {
        $plan = PricingPlan::create([
            'name' => 'Premium Signage Plan',
            'base_price' => 1000.00,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Day',
        ]);

        // Weekend rule: Sat,Sun, 50% markup
        PricingWeekendRule::create([
            'pricing_plan_id' => $plan->id,
            'days' => 'Sat,Sun',
            'pricing_type' => 'percentage',
            'value' => 50.00,
            'status' => 'active',
        ]);

        // 1 Screen, Saturday (weekend day)
        $result = $this->engine->calculate($plan, [
            'screens' => 1,
            'duration_hours' => 1.0,
            'duration_unit' => 'Day',
            'day' => 'Sat',
            'time' => '10:00',
        ]);

        $this->assertEquals(1000.00, $result['base_price']);
        $this->assertEquals(500.00, $result['weekend_amount']);
        $this->assertEquals(1500.00, $result['final_price']);
    }

    public function test_campaigns_calculate_endpoint_uses_rules_engine()
    {
        $plan = PricingPlan::create([
            'name' => 'Premium Signage Plan',
            'base_price' => 4999.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Month',
            'is_default' => true,
            'is_active' => true,
        ]);

        PricingScreenRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_screens' => 5,
            'maximum_screens' => null,
            'discount_type' => 'Percentage',
            'discount_value' => 20.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        PricingDurationRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_duration' => 1.00,
            'maximum_duration' => null,
            'duration_unit' => 'Month',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $country = \App\Models\Country::firstOrCreate(['code' => 'IN'], [
            'name' => 'India',
            'is_active' => true,
        ]);

        $city = \App\Models\City::create([
            'country_id' => $country->id,
            'name' => 'Kochi',
            'is_active' => true,
        ]);

        $screen = \App\Models\Screen::create([
            'name' => 'Test Screen 1',
            'screen_code' => 'TS-0001',
            'screen_type' => 'Billboard',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'location_name' => 'Kochi Center',
            'pricing_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // POST /api/campaigns/calculate
        $response = $this->postJson('/api/campaigns/calculate', [
            'screen_ids' => [$screen->id],
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'daily_start_time' => '10:00',
            'daily_end_time' => '18:00',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'campaign_summary',
                'per_screen',
                'aggregated' => [
                    'base_price',
                    'screen_count',
                    'screen_discount',
                    'duration_discount',
                    'combined_discount',
                    'peak_adjustment',
                    'weekend_adjustment',
                    'first_booking_discount',
                    'referral_discount',
                    'subtotal',
                    'final_amount',
                ]
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals(4999.00, $data['aggregated']['base_price']);
        $this->assertEquals(1, $data['aggregated']['screen_count']);
        $this->assertEquals(0, $data['aggregated']['screen_discount']);
        $this->assertEquals(499.90, $data['aggregated']['duration_discount']);
        $this->assertEquals(499.90, $data['aggregated']['combined_discount']);
        $this->assertEquals(4499.10, $data['aggregated']['final_amount']);
    }
}
