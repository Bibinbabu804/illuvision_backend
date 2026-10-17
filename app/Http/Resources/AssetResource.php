<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'asset_code' => $this->screen_code,
            'screen_code' => $this->screen_code,
            'asset_type' => $this->asset_type?->value ?? $this->asset_type ?? 'fixed',
            'asset_type_label' => $this->asset_type instanceof \App\Enums\AssetType ? $this->asset_type->label() : 'Fixed Digital Screen',
            'pricing_unit' => $this->pricing_unit?->value ?? $this->pricing_unit ?? 'per_screen',
            'pricing_unit_label' => $this->pricing_unit instanceof \App\Domain\Pricing\Enums\PricingUnit ? $this->pricing_unit->label() : 'Per Screen / Day',
            'fleet_size' => $this->fleet_size ?? 1,
            'working_hours_pd' => $this->working_hours_pd,
            'travel_distance_km_pd' => $this->travel_distance_km_pd,
            'display_specs' => $this->display_specs,
            'asset_metadata' => $this->asset_metadata,
            'is_mobile' => $this->is_mobile,
            'screen_type' => $this->screen_type,
            'screen_category_id' => $this->screen_category_id,
            'screen_category' => $this->screenCategory ? [
                'id' => $this->screenCategory->id,
                'name' => $this->screenCategory->name,
                'slug' => $this->screenCategory->slug,
            ] : null,
            'country_id' => $this->country_id,
            'state_id' => $this->city?->state_id,
            'city_id' => $this->city_id,
            'location_name' => $this->location_name,
            'pricing_plan_id' => $this->pricing_plan_id,
            'owner_type' => $this->owner_type,
            'screen_provider_id' => $this->screen_provider_id,
            'illuvision_percentage' => $this->illuvision_percentage !== null ? (float) $this->illuvision_percentage : ($this->screenProvider?->illuvision_percentage ? (float) $this->screenProvider->illuvision_percentage : 10.00),
            'screen_provider_percentage' => $this->screen_provider_percentage !== null ? (float) $this->screen_provider_percentage : ($this->screenProvider?->screen_provider_percentage ? (float) $this->screenProvider->screen_provider_percentage : 90.00),
            'custom_illuvision_percentage' => $this->illuvision_percentage !== null ? (float) $this->illuvision_percentage : null,
            'custom_screen_provider_percentage' => $this->screen_provider_percentage !== null ? (float) $this->screen_provider_percentage : null,
            'has_custom_screen_split' => $this->illuvision_percentage !== null,
            'duration_split_overrides' => $this->whenLoaded('durationSplitOverrides', function () {
                return $this->durationSplitOverrides->map(function ($override) {
                    return [
                        'id' => $override->id,
                        'screen_id' => $override->screen_id,
                        'pricing_plan_id' => $override->pricing_plan_id,
                        'pricing_duration_rule_id' => $override->pricing_duration_rule_id,
                        'illuvision_percentage' => (float) $override->illuvision_percentage,
                        'screen_provider_percentage' => (float) $override->screen_provider_percentage,
                        'is_active' => (bool) $override->is_active,
                    ];
                });
            }),
            'status' => $this->status,
            'booking_status' => $this->booking_status,
            'is_bookable' => $this->is_bookable,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            'thumbnail' => $this->thumbnail
                ? ($request->is('*api/screens*')
                    ? $this->thumbnail
                    : asset('storage/' . $this->thumbnail))
                : null,
            'country' => $this->whenLoaded('country'),
            'state' => $this->city?->state,
            'city' => $this->whenLoaded('city'),
            'pricing_plan' => $this->whenLoaded('pricingPlan'),
            'screen_provider' => $this->whenLoaded('screenProvider'),
            'coverage_cities' => $this->whenLoaded('coverageCities'),
            'coverage_states' => $this->whenLoaded('coverageStates'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
