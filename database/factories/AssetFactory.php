<?php

namespace Database\Factories;

use App\Models\Screen;
use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Screen::class;

    public function definition(): array
    {
        $assetType = $this->faker->randomElement(AssetType::cases());
        $isMobile = $assetType !== AssetType::FIXED;

        return [
            'name' => $this->faker->company() . ' ' . $assetType->label(),
            'screen_code' => 'AST-' . strtoupper($this->faker->bothify('??###')),
            'asset_type' => $assetType->value,
            'pricing_unit' => $isMobile ? PricingUnit::PER_VEHICLE->value : PricingUnit::PER_SCREEN->value,
            'fleet_size' => $isMobile ? $this->faker->numberBetween(2, 10) : 1,
            'working_hours_pd' => $isMobile ? 6 : 18,
            'travel_distance_km_pd' => $isMobile ? $this->faker->numberBetween(40, 100) : null,
            'display_specs' => '7x5 ft High-Brightness LED',
            'screen_type' => 'digital_billboard',
            'country_id' => 1,
            'city_id' => 1,
            'location_name' => $this->faker->streetAddress(),
            'pricing_plan_id' => 1,
            'owner_type' => 'illuvision',
            'status' => 'active',
            'booking_status' => 'available',
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
        ];
    }
}
