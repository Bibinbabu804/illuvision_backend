<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Canonical INR calculation amount — always INR after normalisation
            'basePrice' => (float) $this->base_price,
            // Original commercial configuration (admin-entered amount + currency)
            'basePriceOriginal'    => $this->base_price_original !== null
                ? (float) $this->base_price_original
                : (float) $this->base_price,
            'basePriceCurrency'    => $this->base_price_currency ?? ($this->currency ?? 'INR'),
            'conversionRateToInr'  => $this->conversion_rate_to_inr !== null
                ? (float) $this->conversion_rate_to_inr
                : 1.0,
            // The plan's configured currency (display context for admin)
            'currency' => $this->currency ?? 'INR',
            'minDuration' => (int) $this->minimum_duration,
            'minDurationType' => $this->minimum_duration_type,
            'isDefault' => (bool) $this->is_default,
            'isActive' => (bool) $this->is_active,
            
            'screenRules' => $this->whenLoaded('screenRules', function () {
                return $this->screenRules->map(function ($rule) {
                    return [
                        'id' => $rule->id,
                        'minimumScreens' => (int) $rule->minimum_screens,
                        'maximumScreens' => $rule->maximum_screens ? (int) $rule->maximum_screens : null,
                        'discountType' => $rule->discount_type,
                        'discountValue' => (float) $rule->discount_value,
                        'sortOrder' => (int) $rule->sort_order,
                        'status' => $rule->status,
                    ];
                });
            }, []),

            'durationRules' => $this->whenLoaded('durationRules', function () {
                return $this->durationRules->map(function ($rule) {
                    return [
                        'id' => $rule->id,
                        'minimumDuration' => (float) $rule->minimum_duration,
                        'maximumDuration' => $rule->maximum_duration ? (float) $rule->maximum_duration : null,
                        'durationUnit' => $rule->duration_unit,
                        'discountType' => $rule->discount_type,
                        'discountValue' => (float) $rule->discount_value,
                        'sortOrder' => (int) $rule->sort_order,
                        'status' => $rule->status,
                    ];
                });
            }, []),

            'peakRules' => $this->whenLoaded('peakRules', function () {
                return $this->peakRules->map(function ($rule) {
                    return [
                        'id' => $rule->id,
                        'dayOfWeek' => $rule->day_of_week,
                        'startTime' => substr($rule->start_time, 0, 5),
                        'endTime' => substr($rule->end_time, 0, 5),
                        'pricingType' => $rule->pricing_type,
                        'value' => (float) $rule->value,
                        'status' => $rule->status,
                    ];
                });
            }, []),

            'weekendRules' => $this->whenLoaded('weekendRules', function () {
                return $this->weekendRules->map(function ($rule) {
                    return [
                        'id' => $rule->id,
                        'days' => $rule->days,
                        'pricingType' => $rule->pricing_type,
                        'value' => (float) $rule->value,
                        'status' => $rule->status,
                    ];
                });
            }, []),
            
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
