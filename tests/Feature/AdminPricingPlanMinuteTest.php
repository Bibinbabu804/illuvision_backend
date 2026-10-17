<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Models\PricingDurationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPricingPlanMinuteTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'super-admin',
            'status' => 'active',
        ]);
    }

    /**
     * Test creating a Minute-based Pricing Plan with minute duration rules.
     */
    public function test_admin_can_create_minute_pricing_plan_with_rules()
    {
        $payload = [
            'name' => 'Quick Promo',
            'description' => 'Fast minute-level booking promotions',
            'base_price' => 500.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_default' => false,
            'is_active' => true,
            'duration_rules' => [
                [
                    'minimum_duration' => 15,
                    'maximum_duration' => 29,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 5.00,
                    'sort_order' => 1,
                    'status' => 'active',
                ],
                [
                    'minimum_duration' => 30,
                    'maximum_duration' => 59,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 10.00,
                    'sort_order' => 2,
                    'status' => 'active',
                ],
                [
                    'minimum_duration' => 60,
                    'maximum_duration' => 89,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 15.00,
                    'sort_order' => 3,
                    'status' => 'active',
                ],
                [
                    'minimum_duration' => 90,
                    'maximum_duration' => null,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 20.00,
                    'sort_order' => 4,
                    'status' => 'active',
                ],
            ],
            'screen_rules' => [
                [
                    'minimum_screens' => 2,
                    'maximum_screens' => 5,
                    'discount_type' => 'Percentage',
                    'discount_value' => 10.00,
                    'sort_order' => 1,
                    'status' => 'active',
                ]
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/pricing-plans', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => true,
            'data' => [
                'name' => 'Quick Promo',
                'basePrice' => 500.00,
                'minDuration' => 30,
                'minDurationType' => 'Minute',
            ]
        ]);

        // Verify database persistence
        $this->assertDatabaseHas('pricing_plans', [
            'name' => 'Quick Promo',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'base_price' => 500.00,
        ]);

        $this->assertDatabaseHas('pricing_duration_rules', [
            'minimum_duration' => 30,
            'duration_unit' => 'Minute',
            'discount_value' => 10.00,
        ]);

        $this->assertDatabaseHas('pricing_duration_rules', [
            'minimum_duration' => 90,
            'duration_unit' => 'Minute',
            'discount_value' => 20.00,
        ]);
    }

    /**
     * Test reading a Minute-based Pricing Plan via GET API.
     */
    public function test_admin_can_read_minute_pricing_plan()
    {
        $plan = PricingPlan::create([
            'name' => 'Flash Deal Plan',
            'description' => 'Minute deals',
            'base_price' => 10.00,
            'currency' => 'INR',
            'minimum_duration' => 15,
            'minimum_duration_type' => 'Minute',
            'is_default' => false,
            'is_active' => true,
        ]);

        $rule = PricingDurationRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_duration' => 45,
            'duration_unit' => 'Minute',
            'discount_type' => 'Percentage',
            'discount_value' => 12.50,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/pricing-plans/{$plan->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
            'data' => [
                'id' => $plan->id,
                'name' => 'Flash Deal Plan',
                'basePrice' => 10.00,
                'minDuration' => 15,
                'minDurationType' => 'Minute',
                'durationRules' => [
                    [
                        'minimumDuration' => 45,
                        'durationUnit' => 'Minute',
                        'discountValue' => 12.50,
                    ]
                ]
            ]
        ]);
    }

    /**
     * Test updating a Minute-based Pricing Plan via PUT API.
     */
    public function test_admin_can_update_minute_pricing_plan()
    {
        $plan = PricingPlan::create([
            'name' => 'Original Minute Plan',
            'base_price' => 20.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
        ]);

        $updatePayload = [
            'name' => 'Updated Minute Plan',
            'base_price' => 25.00,
            'currency' => 'INR',
            'minimum_duration' => 45,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
            'duration_rules' => [
                [
                    'minimum_duration' => 60,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 15.00,
                    'sort_order' => 1,
                    'status' => 'active',
                ]
            ]
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/pricing-plans/{$plan->id}", $updatePayload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => true,
            'data' => [
                'name' => 'Updated Minute Plan',
                'basePrice' => 25.00,
                'minDuration' => 45,
                'minDurationType' => 'Minute',
            ]
        ]);

        $this->assertDatabaseHas('pricing_plans', [
            'id' => $plan->id,
            'name' => 'Updated Minute Plan',
            'minimum_duration' => 45,
            'minimum_duration_type' => 'Minute',
            'base_price' => 25.00,
        ]);
    }

    /**
     * Test switching units between Minute, Hour, Day, Month.
     */
    public function test_admin_can_switch_duration_type_between_units()
    {
        $plan = PricingPlan::create([
            'name' => 'Switchable Plan',
            'base_price' => 1000.00,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);

        // Switch to Minute
        $res1 = $this->actingAs($this->admin)->putJson("/api/pricing-plans/{$plan->id}", [
            'name' => 'Switchable Plan',
            'base_price' => 10.00,
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
        ]);
        $res1->assertStatus(200);
        $this->assertEquals('Minute', $plan->fresh()->minimum_duration_type);
        $this->assertEquals(30, $plan->fresh()->minimum_duration);

        // Switch to Day
        $res2 = $this->actingAs($this->admin)->putJson("/api/pricing-plans/{$plan->id}", [
            'name' => 'Switchable Plan',
            'base_price' => 5000.00,
            'minimum_duration' => 2,
            'minimum_duration_type' => 'Day',
        ]);
        $res2->assertStatus(200);
        $this->assertEquals('Day', $plan->fresh()->minimum_duration_type);
        $this->assertEquals(2, $plan->fresh()->minimum_duration);
    }

    /**
     * Test live preview simulation endpoint with Minute unit.
     */
    public function test_pricing_plan_preview_endpoint_with_minute_unit()
    {
        $plan = PricingPlan::create([
            'name' => 'Simulator Minute Plan',
            'base_price' => 10.00, // ₹10 per minute
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
        ]);

        PricingDurationRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_duration' => 60,
            'duration_unit' => 'Minute',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        // Simulate 60 minutes for 2 screens
        $response = $this->actingAs($this->admin)->postJson('/api/pricing-plans/preview', [
            'pricing_plan_id' => $plan->id,
            'screens' => 2,
            'duration_hours' => 60,
            'duration_unit' => 'Minute',
            'day' => 'Mon',
            'time' => '10:00',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Base price: ₹10/min * 60 min * 2 screens = ₹1,200.00
        $this->assertEquals(1200.00, $data['base_price']);

        // Duration discount (10% on >= 60 min): ₹120.00
        $this->assertEquals(120.00, $data['discount_amount']);

        // Final price: ₹1,200 - ₹120 = ₹1,080.00
        $this->assertEquals(1080.00, $data['final_price']);
    }

    /**
     * Test backend validation rejects invalid duration units and zero/negative durations.
     */
    public function test_pricing_plan_validation_rejects_invalid_values()
    {
        // 1. Invalid unit 'Seconds'
        $res1 = $this->actingAs($this->admin)->postJson('/api/pricing-plans', [
            'name' => 'Bad Unit Plan',
            'base_price' => 100.00,
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Seconds',
        ]);
        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['minimum_duration_type']);

        // 2. Zero minimum duration
        $res2 = $this->actingAs($this->admin)->postJson('/api/pricing-plans', [
            'name' => 'Zero Duration Plan',
            'base_price' => 100.00,
            'minimum_duration' => 0,
            'minimum_duration_type' => 'Minute',
        ]);
        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['minimum_duration']);

        // 3. Negative minimum duration
        $res3 = $this->actingAs($this->admin)->postJson('/api/pricing-plans', [
            'name' => 'Negative Duration Plan',
            'base_price' => 100.00,
            'minimum_duration' => -5,
            'minimum_duration_type' => 'Minute',
        ]);
        $res3->assertStatus(422);
        $res3->assertJsonValidationErrors(['minimum_duration']);

        // 4. Invalid duration rule unit
        $res4 = $this->actingAs($this->admin)->postJson('/api/pricing-plans', [
            'name' => 'Bad Rule Unit Plan',
            'base_price' => 100.00,
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'duration_rules' => [
                [
                    'minimum_duration' => 10,
                    'duration_unit' => 'InvalidUnit',
                    'discount_type' => 'Percentage',
                    'discount_value' => 5,
                ]
            ]
        ]);
        $res4->assertStatus(422);
        $res4->assertJsonValidationErrors(['duration_rules.0.duration_unit']);
    }
}
