<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $assetId = $this->route('asset') ?? $this->route('id');

        return [
            'name' => 'sometimes|required|string|max:255',
            'screen_code' => "sometimes|required|string|max:255|unique:screens,screen_code,{$assetId}",
            'asset_type' => ['sometimes', 'required', new Enum(AssetType::class)],
            'pricing_unit' => ['nullable', new Enum(PricingUnit::class)],
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'nullable|exists:screen_categories,id',
            'country_id' => 'sometimes|required|exists:countries,id',
            'city_id' => 'nullable|exists:cities,id',
            'location_name' => 'sometimes|required|string|max:255',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'status' => 'nullable|string|in:active,inactive,maintenance',
            'booking_status' => 'nullable|string|in:available,sold_out',
            'coverage_states' => 'nullable|array',
            'coverage_states.*' => 'exists:states,id',
            'coverage_cities' => 'nullable|array',
            'coverage_cities.*' => 'exists:cities,id',
        ];
    }
}
