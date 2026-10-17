<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\City;
use App\Models\PricingPlan;
use App\Models\PricingScreenRule;
use App\Models\PricingDurationRule;
use App\Models\PricingPeakRule;
use App\Models\PricingWeekendRule;
use App\Models\Screen;
use Illuminate\Database\Seeder;

class KeralaScreensSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Country
        $country = Country::firstOrCreate(
            ['name' => 'India'],
            ['code' => 'IN', 'is_active' => true]
        );

        // 2. Create Cities
        $cities = [];
        $cityNames = ['Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur', 'Alappuzha'];
        foreach ($cityNames as $name) {
            $cities[] = City::firstOrCreate(
                ['country_id' => $country->id, 'name' => $name],
                ['is_active' => true]
            );
        }

        // 3. Create Pricing Plan if none exists
        $plan = PricingPlan::firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Base Pricing Plan',
                'description' => 'Standard pricing plan with duration and screen rules',
                'base_price' => 4999.00,
                'currency' => 'INR',
                'minimum_duration' => 1,
                'minimum_duration_type' => 'Month',
                'is_active' => true,
            ]
        );

        // Seed Screen Rules if none exist
        if ($plan->screenRules()->count() === 0) {
            PricingScreenRule::create([
                'pricing_plan_id' => $plan->id,
                'minimum_screens' => 5,
                'maximum_screens' => 9,
                'discount_type' => 'Percentage',
                'discount_value' => 20.00,
                'sort_order' => 1,
                'status' => 'active',
            ]);
            PricingScreenRule::create([
                'pricing_plan_id' => $plan->id,
                'minimum_screens' => 10,
                'maximum_screens' => null,
                'discount_type' => 'Percentage',
                'discount_value' => 30.00,
                'sort_order' => 2,
                'status' => 'active',
            ]);
        }

        // Seed Duration Rules if none exist
        if ($plan->durationRules()->count() === 0) {
            PricingDurationRule::create([
                'pricing_plan_id' => $plan->id,
                'minimum_duration' => 1.00,
                'maximum_duration' => 2.00,
                'duration_unit' => 'Month',
                'discount_type' => 'Percentage',
                'discount_value' => 0.00,
                'sort_order' => 1,
                'status' => 'active',
            ]);
            PricingDurationRule::create([
                'pricing_plan_id' => $plan->id,
                'minimum_duration' => 3.00,
                'maximum_duration' => 5.00,
                'duration_unit' => 'Month',
                'discount_type' => 'Percentage',
                'discount_value' => 10.00,
                'sort_order' => 2,
                'status' => 'active',
            ]);
            PricingDurationRule::create([
                'pricing_plan_id' => $plan->id,
                'minimum_duration' => 6.00,
                'maximum_duration' => null,
                'duration_unit' => 'Month',
                'discount_type' => 'Percentage',
                'discount_value' => 20.00,
                'sort_order' => 3,
                'status' => 'active',
            ]);
        }

        // Seed Peak Rules if none exist
        if ($plan->peakRules()->count() === 0) {
            PricingPeakRule::create([
                'pricing_plan_id' => $plan->id,
                'day_of_week' => 'All',
                'start_time' => '18:00',
                'end_time' => '22:00',
                'pricing_type' => 'multiplier',
                'value' => 1.20,
                'status' => 'active',
            ]);
        }

        // Seed Weekend Rules if none exist
        if ($plan->weekendRules()->count() === 0) {
            PricingWeekendRule::create([
                'pricing_plan_id' => $plan->id,
                'days' => 'Sat,Sun',
                'pricing_type' => 'multiplier',
                'value' => 1.15,
                'status' => 'active',
            ]);
        }

        // 4. Create 20 Screens in Kerala
        // Geographic bounding box for Kerala: Lat: 8.5 to 12.0 N, Lng: 75.29 to 77.0 E
        $existingScreensCount = Screen::count();
        if ($existingScreensCount < 20) {
            $screensToCreate = 20 - $existingScreensCount;

            $screenTypes = ['LED Billboard', 'Digital Poster', 'Transit Screen', 'Mall Display'];
            $orientations = ['landscape', 'portrait'];
            $resolutions = ['1920x1080', '3840x2160', '1080x1920'];

            for ($i = 0; $i < $screensToCreate; $i++) {
                $city = $cities[$i % count($cities)];
                
                // Generate coords inside Kerala box
                $lat = 8.5 + (($i * 0.17) % 3.5);
                $lng = 75.29 + (($i * 0.08) % 1.71);
                
                Screen::create([
                    'name' => "Kerala Screen " . ($existingScreensCount + $i + 1),
                    'screen_code' => "KL-" . str_pad($existingScreensCount + $i + 1, 4, '0', STR_PAD_LEFT),
                    'screen_type' => $screenTypes[$i % count($screenTypes)],
                    'country_id' => $country->id,
                    'city_id' => $city->id,
                    'location_name' => "Location in " . $city->name,
                    'pricing_plan_id' => $plan->id,
                    'status' => 'active',
                    'orientation' => $orientations[$i % count($orientations)],
                    'resolution' => $resolutions[$i % count($resolutions)],
                    'operating_hours' => '06:00 - 22:00',
                    'latitude' => $lat,
                    'longitude' => $lng,
                ]);
            }
        }
    }
}
