<?php

namespace App\Application\Pricing\Adapters;

use App\Services\PricingEngine;

/**
 * LegacyPricingEngineAdapter
 *
 * Isolates and preserves the legacy multi-asset campaign pricing behavior
 * used by V2 endpoints (/api/v2/pricing/calculate) without duplicating the algorithm.
 */
class LegacyPricingEngineAdapter
{
    public function __construct(
        protected PricingEngine $legacyEngine
    ) {}

    /**
     * Calculate multi-asset campaign pricing preview via legacy engine.
     *
     * @param array $params
     * @return array
     */
    public function calculatePreview(array $params): array
    {
        return $this->legacyEngine->calculatePreview($params);
    }
}
