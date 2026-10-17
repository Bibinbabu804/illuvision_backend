<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'screen_code' => 'nullable|string|max:255|unique:screens,screen_code',
            'asset_type' => ['required', new Enum(AssetType::class)],
            'pricing_unit' => ['nullable', new Enum(PricingUnit::class)],
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'nullable|exists:screen_categories,id',
            'country_id' => 'required|exists:countries,id',
            'city_id' => 'nullable|exists:cities,id',
            'location_name' => 'required|string|max:255',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'owner_type' => 'nullable|string|in:illuvision,screen_provider',
            'screen_provider_id' => 'nullable|exists:screen_providers,id',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'coverage_states' => 'nullable|array',
            'coverage_states.*' => 'exists:states,id',
            'coverage_cities' => 'nullable|array',
            'coverage_cities.*' => 'exists:cities,id',
        ];
    }
}
