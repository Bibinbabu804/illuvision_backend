<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $covLocations = [];
        if (!empty($this->asset_metadata)) {
            $metadata = is_string($this->asset_metadata) ? json_decode($this->asset_metadata, true) : $this->asset_metadata;
            if (is_array($metadata) && isset($metadata['coverage_locations']) && is_array($metadata['coverage_locations'])) {
                $covLocations = $metadata['coverage_locations'];
            }
        }

        $assetTypeStr = $this->asset_type?->value ?? $this->asset_type ?? 'fixed';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'screen_code' => $this->screen_code,
            'asset_code' => $this->screen_code,
            'asset_type' => $assetTypeStr,
            'pricing_unit' => $this->pricing_unit?->value ?? $this->pricing_unit ?? 'per_screen',
            'fleet_size' => $this->fleet_size ?? 1,
            'working_hours_pd' => $this->working_hours_pd,
            'travel_distance_km_pd' => $this->travel_distance_km_pd,
            'asset_metadata' => $this->asset_metadata,
            'coverage_locations' => $covLocations,
            'is_mobile' => (bool) ($this->is_mobile ?? ($assetTypeStr === 'mobile' || $assetTypeStr === 'vehicle' || $assetTypeStr === 'fleet')),
            'screen_type' => $this->screen_type,
            'screen_category_id' => $this->screen_category_id,
            'country_id' => $this->country_id,
            'state_id' => $this->city?->state_id,
            'city_id' => $this->city_id,
            'location_name' => $this->location_name,
            'pricing_plan_id' => $this->pricing_plan_id,
            'status' => $this->status,
            'booking_status' => $this->booking_status,
            'already_reserved' => $this->already_reserved ?? 0,
            'orientation' => $this->orientation,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'thumbnail' => $this->thumbnail
                ? ($request->is('*api/screens*')
                    ? $this->thumbnail
                    : asset('storage/' . $this->thumbnail))
                : null,
            'city' => $this->relationLoaded('city') && $this->city ? [
                'id' => $this->city->id,
                'name' => $this->city->name,
                'state_id' => $this->city->state_id,
            ] : null,
            'pricing_plan' => $this->relationLoaded('pricingPlan') && $this->pricingPlan ? [
                'id'                   => $this->pricingPlan->id,
                'base_price'           => $this->pricingPlan->base_price,           // canonical INR
                'currency'             => $this->pricingPlan->currency ?? 'INR',    // original plan currency
                'base_price_original'  => $this->pricingPlan->base_price_original,  // original admin amount
                'base_price_currency'  => $this->pricingPlan->base_price_currency ?? 'INR',
            ] : null,
            'coverage_cities' => $this->relationLoaded('coverageCities') && $this->coverageCities ? $this->coverageCities->map(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'state_id' => $c->state_id,
                    'country_id' => $c->country_id,
                ];
            })->values() : [],
            'coverage_states' => $this->relationLoaded('coverageStates') && $this->coverageStates ? $this->coverageStates->map(function ($s) {
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'country_id' => $s->country_id,
                ];
            })->values() : [],
        ];
    }
}
