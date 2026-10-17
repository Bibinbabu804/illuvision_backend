<?php

namespace App\Services\Campaign;

use App\Models\Screen;
use Illuminate\Database\Eloquent\Collection;

class RecommendationService
{
    /**
     * Get smart asset recommendations based on city, budget, or asset type.
     */
    public function getRecommendations(?int $cityId = null, ?float $maxBudget = null, ?string $assetType = null): Collection
    {
        $query = Screen::with(['country', 'city', 'pricingPlan', 'coverageCities'])
            ->where('status', 'active');

        if ($cityId) {
            $query->forCity($cityId);
        }

        if ($assetType) {
            $query->where('asset_type', $assetType);
        }

        $assets = $query->limit(10)->get();

        if ($maxBudget) {
            return $assets->filter(function ($asset) use ($maxBudget) {
                $price = $asset->pricingPlan?->base_price ? (float) $asset->pricingPlan->base_price : 1000.0;
                return $price <= $maxBudget;
            });
        }

        return $assets;
    }
}
