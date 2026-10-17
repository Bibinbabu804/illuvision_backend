<?php

namespace Database\Seeders;

use App\Models\PricingPlan;
use Illuminate\Database\Seeder;

class PricingPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter Campaign',
                'description' => 'Ideal for small businesses and local promotions.',
                'base_price' => 4999.00,
                'currency' => 'INR',
                'minimum_duration' => 1,
                'minimum_duration_type' => 'Month',
                'is_default' => false,
                'is_active' => true,
                'screen_rules' => [
                    [
                        'minimum_screens' => 1,
                        'maximum_screens' => 1,
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 2,
                        'maximum_screens' => 2,
                        'discount_type' => 'Percentage',
                        'discount_value' => 5.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 3,
                        'maximum_screens' => 4,
                        'discount_type' => 'Percentage',
                        'discount_value' => 10.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 5,
                        'maximum_screens' => 9,
                        'discount_type' => 'Percentage',
                        'discount_value' => 20.00,
                        'sort_order' => 4,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 10,
                        'maximum_screens' => null,
                        'discount_type' => 'Percentage',
                        'discount_value' => 28.00,
                        'sort_order' => 5,
                        'status' => 'active',
                    ],
                ],
                'duration_rules' => [
                    [
                        'minimum_duration' => 1.00,
                        'maximum_duration' => 2.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 3.00,
                        'maximum_duration' => 5.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 10.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 6.00,
                        'maximum_duration' => null,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 20.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                ],
                'peak_rules' => [
                    [
                        'day_of_week' => 'Mon,Tue,Wed,Thu,Fri',
                        'start_time' => '18:00:00',
                        'end_time' => '22:00:00',
                        'pricing_type' => 'multiplier',
                        'value' => 1.15,
                        'status' => 'active',
                    ],
                ],
                'weekend_rules' => [
                    [
                        'days' => 'Sat,Sun',
                        'pricing_type' => 'multiplier',
                        'value' => 1.10,
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'name' => 'Business Growth',
                'description' => 'Designed for growing businesses targeting multiple locations.',
                'base_price' => 6999.00,
                'currency' => 'INR',
                'minimum_duration' => 1,
                'minimum_duration_type' => 'Month',
                'is_default' => true,
                'is_active' => true,
                'screen_rules' => [
                    [
                        'minimum_screens' => 1,
                        'maximum_screens' => 2,
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 3,
                        'maximum_screens' => 4,
                        'discount_type' => 'Percentage',
                        'discount_value' => 8.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 5,
                        'maximum_screens' => 9,
                        'discount_type' => 'Percentage',
                        'discount_value' => 18.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 10,
                        'maximum_screens' => 19,
                        'discount_type' => 'Percentage',
                        'discount_value' => 30.00,
                        'sort_order' => 4,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 20,
                        'maximum_screens' => null,
                        'discount_type' => 'Percentage',
                        'discount_value' => 38.00,
                        'sort_order' => 5,
                        'status' => 'active',
                    ],
                ],
                'duration_rules' => [
                    [
                        'minimum_duration' => 1.00,
                        'maximum_duration' => 2.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 3.00,
                        'maximum_duration' => 5.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 12.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 6.00,
                        'maximum_duration' => 11.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 24.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 12.00,
                        'maximum_duration' => null,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 35.00,
                        'sort_order' => 4,
                        'status' => 'active',
                    ],
                ],
                'peak_rules' => [
                    [
                        'day_of_week' => 'Mon,Tue,Wed,Thu,Fri',
                        'start_time' => '17:00:00',
                        'end_time' => '22:00:00',
                        'pricing_type' => 'multiplier',
                        'value' => 1.20,
                        'status' => 'active',
                    ],
                ],
                'weekend_rules' => [
                    [
                        'days' => 'Sat,Sun',
                        'pricing_type' => 'multiplier',
                        'value' => 1.15,
                        'status' => 'active',
                    ],
                ],
            ],
            [
                'name' => 'Enterprise Network',
                'description' => 'Best suited for nationwide and high-volume advertising campaigns.',
                'base_price' => 9999.00,
                'currency' => 'INR',
                'minimum_duration' => 1,
                'minimum_duration_type' => 'Month',
                'is_default' => false,
                'is_active' => true,
                'screen_rules' => [
                    [
                        'minimum_screens' => 1,
                        'maximum_screens' => 4,
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 5,
                        'maximum_screens' => 9,
                        'discount_type' => 'Percentage',
                        'discount_value' => 10.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 10,
                        'maximum_screens' => 19,
                        'discount_type' => 'Percentage',
                        'discount_value' => 25.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 20,
                        'maximum_screens' => 49,
                        'discount_type' => 'Percentage',
                        'discount_value' => 35.00,
                        'sort_order' => 4,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 50,
                        'maximum_screens' => 99,
                        'discount_type' => 'Percentage',
                        'discount_value' => 45.00,
                        'sort_order' => 5,
                        'status' => 'active',
                    ],
                    [
                        'minimum_screens' => 100,
                        'maximum_screens' => null,
                        'discount_type' => 'Percentage',
                        'discount_value' => 55.00,
                        'sort_order' => 6,
                        'status' => 'active',
                    ],
                ],
                'duration_rules' => [
                    [
                        'minimum_duration' => 1.00,
                        'maximum_duration' => 2.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 0.00,
                        'sort_order' => 1,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 3.00,
                        'maximum_duration' => 5.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 15.00,
                        'sort_order' => 2,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 6.00,
                        'maximum_duration' => 11.99,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 30.00,
                        'sort_order' => 3,
                        'status' => 'active',
                    ],
                    [
                        'minimum_duration' => 12.00,
                        'maximum_duration' => null,
                        'duration_unit' => 'Month',
                        'discount_type' => 'Percentage',
                        'discount_value' => 40.00,
                        'sort_order' => 4,
                        'status' => 'active',
                    ],
                ],
                'peak_rules' => [
                    [
                        'day_of_week' => 'Mon,Tue,Wed,Thu,Fri',
                        'start_time' => '17:00:00',
                        'end_time' => '23:00:00',
                        'pricing_type' => 'multiplier',
                        'value' => 1.25,
                        'status' => 'active',
                    ],
                ],
                'weekend_rules' => [
                    [
                        'days' => 'Sat,Sun',
                        'pricing_type' => 'multiplier',
                        'value' => 1.20,
                        'status' => 'active',
                    ],
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $screenRules = $planData['screen_rules'];
            $durationRules = $planData['duration_rules'];
            $peakRules = $planData['peak_rules'];
            $weekendRules = $planData['weekend_rules'];

            unset(
                $planData['screen_rules'],
                $planData['duration_rules'],
                $planData['peak_rules'],
                $planData['weekend_rules']
            );

            // If this plan is designated as default, unset default flag on all other existing plans
            if ($planData['is_default']) {
                PricingPlan::where('name', '!=', $planData['name'])->update(['is_default' => false]);
            }

            // Create or update the core Pricing Plan
            $plan = PricingPlan::updateOrCreate(
                ['name' => $planData['name']],
                $planData
            );

            // Re-seed Screen Rules to keep it simple, clean, and idempotent
            $plan->screenRules()->delete();
            foreach ($screenRules as $rule) {
                $plan->screenRules()->create($rule);
            }

            // Re-seed Duration Rules
            $plan->durationRules()->delete();
            foreach ($durationRules as $rule) {
                $plan->durationRules()->create($rule);
            }

            // Re-seed Peak Rules
            $plan->peakRules()->delete();
            foreach ($peakRules as $rule) {
                $plan->peakRules()->create($rule);
            }

            // Re-seed Weekend Rules
            $plan->weekendRules()->delete();
            foreach ($weekendRules as $rule) {
                $plan->weekendRules()->create($rule);
            }
        }
    }
}
