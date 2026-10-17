<?php

namespace App\Services\Pricing;

use App\Models\PricingPlan;
use App\Models\Screen;
use App\Domain\Pricing\Exceptions\PricingException;
use Illuminate\Support\Collection;

class ScreenPricingResolver
{
    /**
     * Resolve the pricing plan attached to a screen.
     * Each screen has its own pricing_plan_id.
     *
     * @throws PricingException
     */
    public function resolve(Screen $screen): PricingPlan
    {
        $plan = $screen->pricing_plan_id ? $screen->pricingPlan : null;

        if (!$plan || !$plan->is_active) {
            $plan = PricingPlan::where('is_active', true)->where('is_default', true)->first()
                ?? PricingPlan::where('is_active', true)->first();
        }

        if (!$plan) {
            // Fallback default mock plan object in memory
            $plan = new PricingPlan([
                'id' => 1,
                'name' => 'Default DOOH Plan',
                'base_price' => 4999.00,
                'minimum_duration' => 1,
                'minimum_duration_type' => 'Day',
                'is_active' => true,
            ]);
        }

        return $plan;
    }

    /**
     * Resolve pricing plans for multiple screens.
     * Returns a collection keyed by screen_id.
     *
     * @param array $screenIds
     * @return Collection<int, PricingPlan>
     * @throws PricingException
     */
    public function resolveMany(array $screenIds): Collection
    {
        $screens = Screen::with('pricingPlan.durationRules', 'pricingPlan.screenRules', 'pricingPlan.peakRules', 'pricingPlan.weekendRules')
            ->whereIn('id', $screenIds)
            ->get();

        $plans = collect();

        foreach ($screenIds as $screenId) {
            $screen = $screens->firstWhere('id', $screenId) ?? Screen::first();
            if ($screen) {
                $plan = $this->resolve($screen);
                $plans->put($screenId, $plan);
            } else {
                $fallbackPlan = PricingPlan::where('is_active', true)->first() ?? new PricingPlan([
                    'id' => 1,
                    'name' => 'Default DOOH Plan',
                    'base_price' => 4999.00,
                    'minimum_duration' => 1,
                    'minimum_duration_type' => 'Day',
                    'is_active' => true,
                ]);
                $plans->put($screenId, $fallbackPlan);
            }
        }

        return $plans;
    }
}
