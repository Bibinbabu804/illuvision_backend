<?php

namespace Database\Seeders;

use App\Models\Screen;
use App\Models\City;
use App\Models\PricingPlan;
use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    public function run(): void
    {
        $kochi = City::where('name', 'like', '%Kochi%')->first() ?? City::first();
        $thrissur = City::where('name', 'like', '%Thrissur%')->first();
        $kottayam = City::where('name', 'like', '%Kottayam%')->first();
        $pricingPlanId = PricingPlan::first()?->id;

        // Sample LED Van Fleet
        $van = Screen::create([
            'name' => 'Super Carry LED Van Campaign',
            'screen_code' => 'AST-VAN-001',
            'asset_type' => AssetType::MOBILE_VAN->value,
            'pricing_unit' => PricingUnit::PER_VEHICLE->value,
            'fleet_size' => 3,
            'working_hours_pd' => 6,
            'travel_distance_km_pd' => 60,
            'display_specs' => '7x5 ft P4 High Brightness LED',
            'screen_type' => 'led_van',
            'country_id' => $kochi?->country_id ?? 1,
            'city_id' => $kochi?->id ?? 1,
            'location_name' => 'Kochi Central Depot',
            'pricing_plan_id' => $pricingPlanId,
            'owner_type' => 'illuvision',
            'status' => 'active',
            'booking_status' => 'available',
            'latitude' => 9.9312,
            'longitude' => 76.2673,
        ]);

        if ($kochi && $thrissur) {
            $van->coverageCities()->sync([
                $kochi->id => ['is_primary' => true, 'priority' => 1],
                $thrissur->id => ['is_primary' => false, 'priority' => 2],
            ]);
            if ($kottayam) {
                $van->coverageCities()->attach($kottayam->id, ['is_primary' => false, 'priority' => 3]);
            }
        }

        // Sample Backpack Team
        $backpack = Screen::create([
            'name' => 'Kochi Student Backpack Promo Team',
            'screen_code' => 'AST-BP-001',
            'asset_type' => AssetType::BACKPACK_TEAM->value,
            'pricing_unit' => PricingUnit::PER_TEAM->value,
            'fleet_size' => 5,
            'working_hours_pd' => 4,
            'travel_distance_km_pd' => 15,
            'display_specs' => 'Wearable 19-inch Ultra-Bright LED',
            'screen_type' => 'backpack_team',
            'country_id' => $kochi?->country_id ?? 1,
            'city_id' => $kochi?->id ?? 1,
            'location_name' => 'Marine Drive & MG Road Hub',
            'pricing_plan_id' => $pricingPlanId,
            'owner_type' => 'illuvision',
            'status' => 'active',
            'booking_status' => 'available',
            'latitude' => 9.9723,
            'longitude' => 76.2784,
        ]);

        if ($kochi) {
            $backpack->coverageCities()->sync([
                $kochi->id => ['is_primary' => true, 'priority' => 1],
            ]);
        }
    }
}
